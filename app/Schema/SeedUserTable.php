<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2025 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Schema;

use Fisharebest\Webtrees\DB;

class SeedUserTable implements SeedInterface
{
    public function run(): void
    {
        // Add a "default" user, to store default settings
        DB::identityInsert(table: 'user', callback: static function (): void {
            DB::table(table: 'user')->updateOrInsert(attributes: [
                'user_id' => -1,
            ], values: [
                'user_name' => 'DEFAULT_USER',
                'real_name' => 'DEFAULT_USER',
                'email'     => 'DEFAULT_USER',
                'password'  => 'DEFAULT_USER',
                'secret'    => 'DEFAULT_USER',
            ]);
        });
    }
}
