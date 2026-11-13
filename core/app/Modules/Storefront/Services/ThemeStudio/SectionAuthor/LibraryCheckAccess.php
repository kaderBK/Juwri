<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use App\Console\Commands\StudioLibraryCheck;

/**
 * Theme Studio phase E1: studio:library-check's empty-store dataset and
 * feature-switch map, reused (not copied) so an AI-written section is
 * rendered against exactly the scenarios a library section is.
 */
class LibraryCheckAccess extends StudioLibraryCheck
{
    /** @return array<string,mixed> */
    public function emptyVars(): array
    {
        return $this->emptyStoreVars();
    }

    /** @return array<string,bool> */
    public function allFeatures(bool $on): array
    {
        return $this->features($on);
    }
}
