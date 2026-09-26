<?php

declare(strict_types=1);

/*
| The storefront CMS (spec §24.10): tenant domains only. The blog, FAQs
| and testimonials need content_marketing (admin reads stay open while it
| is inactive, per the module registry).
*/

(require base_path('routes/shared/cms.php'))('tenant.public', 'tenant.admin', 'tenant', ['feature:content_marketing']);
