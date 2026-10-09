/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { generateFilePath } from '@nextcloud/router'

// Run before app/router imports: API routes and physical app assets have different roots.
__webpack_public_path__ = generateFilePath('chorestrike', '', 'js/')
