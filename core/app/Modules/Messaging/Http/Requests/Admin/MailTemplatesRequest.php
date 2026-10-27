<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Requests\Admin;

use Juwri\Modules\Messaging\Support\MailTemplateCatalog;
use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Save the e-mail templates screen: only the catalogued subject/message settings. */
class MailTemplatesRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_fill_keys(MailTemplateCatalog::settingKeys(), ['nullable', 'string']);
    }

    /** @return array<string, string|null> setting name => new value, for the fields that were sent */
    public function settings(): array
    {
        return $this->safe()->only(array_keys(array_intersect_key($this->all(), $this->rules())));
    }
}
