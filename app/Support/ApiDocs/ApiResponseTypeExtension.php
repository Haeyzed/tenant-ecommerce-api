<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use App\Shared\Http\APIResponse;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\MixedType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Types APIResponse::success/created/accepted/noContent/error for the
 * OpenAPI documents (frontend spec BG-06, docs only). APIResponse takes
 * `mixed $data`, so without this Scramble documents every `data` as a
 * string. The call's own argument type becomes `data`, wrapped in the
 * envelope {success, message, data, meta, errors}; a paginator (or a
 * resource collection over one) becomes a list plus meta.pagination and
 * meta.links, as APIResponse::resolveData builds them.
 */
final class ApiResponseTypeExtension implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === APIResponse::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        return match ($event->name) {
            'success' => $this->success($event->getArg('data', 0, new NullType), $event->getArg('status', 3, new LiteralIntegerType(200))),
            'created' => $this->success($event->getArg('data', 0, new NullType), new LiteralIntegerType(201)),
            'accepted' => $this->success($event->getArg('data', 0, new NullType), new LiteralIntegerType(202)),
            'noContent' => $this->success(new NullType, new LiteralIntegerType(200)),
            'error' => $this->response(self::envelope(
                false,
                new NullType,
                [new ArrayItemType_('error_code', self::stringOr($event->getArg('errorCode', 0))), new ArrayItemType_('details', new ArrayType(new MixedType, new StringType))],
                new ArrayType(new ArrayType(new StringType), new StringType),
            ), $event->getArg('status', 2, new IntegerType)),
            default => null,
        };
    }

    private function success(Type $data, Type $status): Generic
    {
        $meta = [];

        if (($paginator = $this->paginator($data)) !== null) {
            [$data, $meta] = $paginator;
        }

        return $this->response(self::envelope(true, $data, $meta, new ArrayType(new MixedType, new StringType)), $status);
    }

    private function response(KeyedArrayType $body, Type $status): Generic
    {
        return new Generic(JsonResponse::class, [$body, $status instanceof LiteralIntegerType ? $status : new LiteralIntegerType(200), new ArrayType]);
    }

    /**
     * @param  list<ArrayItemType_>  $meta
     */
    private static function envelope(bool $success, Type $data, array $meta, Type $errors): KeyedArrayType
    {
        return new KeyedArrayType([
            new ArrayItemType_('success', new LiteralBooleanType($success)),
            new ArrayItemType_('message', new StringType),
            new ArrayItemType_('data', $data),
            new ArrayItemType_('meta', $meta === [] ? new ArrayType(new MixedType, new StringType) : new KeyedArrayType($meta)),
            new ArrayItemType_('errors', $errors),
        ]);
    }

    /**
     * [list data, meta items] for a paginator or a resource collection over
     * one, else null.
     *
     * @return array{0: Type, 1: list<ArrayItemType_>}|null
     */
    private function paginator(Type $data): ?array
    {
        if (! $data instanceof ObjectType) {
            return null;
        }

        if ($data->isInstanceOf(ResourceCollection::class)) {
            $resource = $data instanceof Generic ? ($data->templateTypes[0] ?? null) : null;

            return $resource instanceof ObjectType && $this->isPaginator($resource) ? [$data, $this->paginationMeta($resource)] : null;
        }

        if (! $this->isPaginator($data)) {
            return null;
        }

        $item = $data instanceof Generic ? ($data->templateTypes[1] ?? $data->templateTypes[0] ?? new UnknownType) : new UnknownType;

        return [new ArrayType($item), $this->paginationMeta($data)];
    }

    private function isPaginator(ObjectType $type): bool
    {
        return $type->isInstanceOf(LengthAwarePaginator::class) || $type->isInstanceOf(Paginator::class) || $type->isInstanceOf(CursorPaginator::class);
    }

    /**
     * @return list<ArrayItemType_>
     */
    private function paginationMeta(ObjectType $paginator): array
    {
        $url = new Union([new StringType, new NullType]);
        $int = new Union([new IntegerType, new NullType]);

        if ($paginator->isInstanceOf(CursorPaginator::class)) {
            return [
                new ArrayItemType_('pagination', new KeyedArrayType([
                    new ArrayItemType_('per_page', new IntegerType),
                    new ArrayItemType_('next_cursor', $url),
                    new ArrayItemType_('prev_cursor', $url),
                ])),
                new ArrayItemType_('links', new KeyedArrayType([new ArrayItemType_('next', $url), new ArrayItemType_('prev', $url)])),
            ];
        }

        $lengthAware = $paginator->isInstanceOf(LengthAwarePaginator::class);

        return [
            new ArrayItemType_('pagination', new KeyedArrayType([
                new ArrayItemType_('current_page', new IntegerType),
                new ArrayItemType_('per_page', new IntegerType),
                new ArrayItemType_('from', $int),
                new ArrayItemType_('to', $int),
                ...($lengthAware ? [new ArrayItemType_('total', new IntegerType), new ArrayItemType_('last_page', new IntegerType)] : []),
            ])),
            new ArrayItemType_('links', new KeyedArrayType([
                new ArrayItemType_('first', $url),
                new ArrayItemType_('prev', $url),
                new ArrayItemType_('next', $url),
                ...($lengthAware ? [new ArrayItemType_('last', $url)] : []),
            ])),
        ];
    }

    private static function stringOr(Type $type): Type
    {
        return $type instanceof LiteralStringType ? $type : new StringType;
    }
}
