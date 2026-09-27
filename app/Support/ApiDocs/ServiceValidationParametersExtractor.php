<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Documents request bodies validated inside services (docs only).
 *
 * Most controllers here hand `$request->all()` to a service that validates
 * with `validator($data, [...])`, which Scramble cannot see. For an
 * operation with no documented body, this follows the controller's
 * `$this->service->method($request->all(), ...)` call into the service
 * (and its private helpers, up to three levels), reads the literal rules
 * array and hands it to Scramble's own rules-to-schema conversion.
 * Rule objects other than Rule::in are skipped; `$req`/`$required`
 * placeholders count as required on POST and optional otherwise.
 */
final class ServiceValidationParametersExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    /** @var array<string, list<Node\Stmt>> */
    private static array $files = [];

    public function __construct(private readonly TypeTransformer $openApiTransformer) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $method = mb_strtolower($routeInfo->method);

        if (in_array($method, RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY, true) || $this->hasBody($parameterExtractionResults)) {
            return $parameterExtractionResults;
        }

        try {
            $rules = $this->rulesFor($routeInfo, $method === 'post');
        } catch (Throwable) {
            return $parameterExtractionResults;
        }

        if ($rules === []) {
            return $parameterExtractionResults;
        }

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $this->makeParameters($rules, $this->openApiTransformer, [], 'body'),
        );

        return $parameterExtractionResults;
    }

    /**
     * @param  ParametersExtractionResult[]  $results
     */
    private function hasBody(array $results): bool
    {
        foreach ($results as $result) {
            foreach ($result->parameters as $parameter) {
                if ($parameter->in === 'body') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rulesFor(RouteInfo $routeInfo, bool $creating): array
    {
        $controller = $routeInfo->className();
        $action = $routeInfo->methodName();

        if ($controller === null || $action === null) {
            return [];
        }

        $actionNode = $this->method($controller, $action);

        if ($actionNode === null) {
            return [];
        }

        foreach ((new NodeFinder)->findInstanceOf($actionNode, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! $this->passesRequestData($call)) {
                continue;
            }

            $service = $this->receiverClass($controller, $actionNode, $call->var);

            if ($service !== null) {
                $rules = $this->rulesIn($service, $call->name->name, $creating, 0);

                if ($rules !== []) {
                    return $rules;
                }
            }
        }

        return [];
    }

    /**
     * A call whose arguments include $request->all(), ->only(), ->except()
     * or ->input(), directly or wrapped (Arr::except($request->all(), ...)).
     */
    private function passesRequestData(Node\Expr\MethodCall $call): bool
    {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $found = (new NodeFinder)->findFirst($arg->value, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
                && $n->var instanceof Node\Expr\Variable && $n->var->name === 'request'
                && $n->name instanceof Node\Identifier && in_array($n->name->name, ['all', 'only', 'except', 'input', 'validated'], true));

            if ($found !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class of `$this->property` (typed property, constructor-promoted
     * or not) or of a typed action parameter.
     */
    private function receiverClass(string $controller, Node\Stmt\ClassMethod $action, Node\Expr $receiver): ?string
    {
        if ($receiver instanceof Node\Expr\PropertyFetch && $receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier) {
            $reflection = new ReflectionClass($controller);

            if (! $reflection->hasProperty($receiver->name->name)) {
                return null;
            }

            $type = $reflection->getProperty($receiver->name->name)->getType();

            return $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
        }

        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            foreach ($action->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && $param->var->name === $receiver->name && $param->type instanceof Node\Name) {
                    return $param->type->toString();
                }
            }
        }

        return null;
    }

    /**
     * The first literal rules array validated in the method, following
     * calls to the class's own methods.
     *
     * @return array<string, list<string>>
     */
    private function rulesIn(string $class, string $method, bool $creating, int $depth): array
    {
        $node = $this->method($class, $method);

        if ($node === null || $depth > 3) {
            return [];
        }

        $finder = new NodeFinder;

        // validator($data, $rules) and Validator::make($data, $rules)
        $validations = array_filter(
            [...$finder->findInstanceOf($node, Node\Expr\FuncCall::class), ...$finder->findInstanceOf($node, Node\Expr\StaticCall::class)],
            static fn (Node\Expr $call): bool => isset($call->args[1]) && $call->args[1] instanceof Node\Arg && (
                ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && $call->name->toString() === 'validator')
                || ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && str_ends_with($call->class->toString(), 'Validator')
                    && $call->name instanceof Node\Identifier && $call->name->name === 'make')
            ),
        );

        foreach ($validations as $call) {
            $rules = $this->rulesFromExpr($class, $node, $call->args[1]->value, $creating, $depth);

            if ($rules !== []) {
                return $rules;
            }
        }

        foreach ([...$finder->findInstanceOf($node, Node\Expr\MethodCall::class), ...$finder->findInstanceOf($node, Node\Expr\StaticCall::class)] as $call) {
            $own = $call instanceof Node\Expr\MethodCall
                ? $call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
                : $call->class instanceof Node\Name && in_array($call->class->toString(), ['self', 'static', $class], true);

            if ($own && $call->name instanceof Node\Identifier && $call->name->name !== $method) {
                $rules = $this->rulesIn($class, $call->name->name, $creating, $depth + 1);

                if ($rules !== []) {
                    return $rules;
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rulesFromExpr(string $class, Node\Stmt\ClassMethod $method, Node\Expr $expr, bool $creating, int $depth): array
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $this->rulesArray($class, $expr, $creating);
        }

        // $rules = [...]; validator($data, $rules)
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $assign = (new NodeFinder)->findFirst($method, static fn (Node $n): bool => $n instanceof Node\Expr\Assign
                && $n->var instanceof Node\Expr\Variable && $n->var->name === $expr->name && $n->expr instanceof Node\Expr\Array_);

            return $assign instanceof Node\Expr\Assign && $assign->expr instanceof Node\Expr\Array_ ? $this->rulesArray($class, $assign->expr, $creating) : [];
        }

        // validator($data, $this->rules(...)) or self::rules(...)
        if (($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) && $expr->name instanceof Node\Identifier) {
            $node = $this->method($class, $expr->name->name);
            $return = $node === null ? null : (new NodeFinder)->findFirst($node, static fn (Node $n): bool => $n instanceof Node\Stmt\Return_ && $n->expr instanceof Node\Expr\Array_);

            return $return instanceof Node\Stmt\Return_ && $return->expr instanceof Node\Expr\Array_ ? $this->rulesArray($class, $return->expr, $creating) : [];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rulesArray(string $class, Node\Expr\Array_ $array, bool $creating): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            // ...array_fill_keys(Model::FLAGS, ['sometimes', 'boolean'])
            if ($item->unpack && $item->value instanceof Node\Expr\FuncCall && $item->value->name instanceof Node\Name
                && $item->value->name->toString() === 'array_fill_keys' && count($item->value->args) === 2) {
                $keys = $this->values($class, $item->value->args[0]->value);
                $fieldRules = $this->ruleList($class, $item->value->args[1]->value, $creating);

                foreach ((array) $keys as $key) {
                    $rules[(string) $key] = $fieldRules;
                }

                continue;
            }

            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $rules[$item->key->value] = $this->ruleList($class, $item->value, $creating);
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function ruleList(string $class, Node\Expr $expr, bool $creating): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return explode('|', $expr->value);
        }

        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }

        $list = [];

        foreach ($expr->items as $item) {
            $value = $item?->value;

            if ($value instanceof Node\Scalar\String_) {
                $list[] = $value->value;
            } elseif ($value instanceof Node\Expr\Variable && in_array($value->name, ['req', 'required'], true)) {
                if ($creating) {
                    $list[] = 'required';
                }
            } elseif ($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name && str_ends_with($value->class->toString(), 'Rule')
                && $value->name instanceof Node\Identifier && $value->name->name === 'in' && isset($value->args[0])) {
                $values = $this->values($class, $value->args[0]->value);

                if (is_array($values) && $values !== []) {
                    $list[] = 'in:'.implode(',', array_map('strval', $values));
                }
            }
        }

        return $list;
    }

    /**
     * Evaluates a literal list, a class constant or array_keys() of one.
     */
    private function values(string $class, Node\Expr $expr): mixed
    {
        if ($expr instanceof Node\Expr\Array_) {
            return array_values(array_filter(array_map(static fn (?Node\ArrayItem $i) => match (true) {
                $i?->value instanceof Node\Scalar\String_, $i?->value instanceof Node\Scalar\Int_ => $i->value->value,
                default => null,
            }, $expr->items), static fn ($v): bool => $v !== null));
        }

        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            $owner = in_array($expr->class->toString(), ['self', 'static'], true) ? $class : $expr->class->toString();

            return (new ReflectionClass($owner))->getConstant($expr->name->name);
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toString() === 'array_keys' && isset($expr->args[0])) {
            $inner = $this->values($class, $expr->args[0]->value);

            return is_array($inner) ? array_keys($inner) : null;
        }

        return null;
    }

    private function method(string $class, string $method): ?Node\Stmt\ClassMethod
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($method)) {
            return null;
        }

        $declaring = $reflection->getMethod($method)->getDeclaringClass();
        $file = (string) $declaring->getFileName();

        if ($file === '' || ! str_contains(str_replace('\\', '/', $file), '/app/')) {
            return null;
        }

        $statements = self::$files[$file] ??= $this->parse($file);

        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Stmt\ClassMethod::class) as $node) {
            if ($node->name->toString() === $method) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function parse(string $file): array
    {
        $statements = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return array_values($traverser->traverse($statements));
    }
}
