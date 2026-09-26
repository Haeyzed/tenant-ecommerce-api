<?php

declare(strict_types=1);

/*
| The platform website's CMS (spec §24.10): landlord domains only, no
| feature key; permissions derived for guard platform.
*/

(require base_path('routes/shared/cms.php'))('landlord.public', 'landlord.admin', 'landlord', []);
