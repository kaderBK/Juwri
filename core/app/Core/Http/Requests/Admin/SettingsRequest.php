<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;

use App\Support\Admin\SettingsSections;

/** One section of Admin › Settings (see App\Support\Admin\SettingsSections). */
class SettingsRequest extends PanelFormRequest
{
    public function section(): ?string
    {
        return SettingsSections::detect($this->all());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return SettingsSections::rules($this->section());
    }

    /**
     * The posted options this section may store (files excluded; the
     * controller adds stored file names itself).
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->except(array_keys($this->allFiles())) as $name => $value) {
            if (SettingsSections::writable((string) $name)) {
                $options[$name] = $value;
            }
        }

        return $options;
    }
}
