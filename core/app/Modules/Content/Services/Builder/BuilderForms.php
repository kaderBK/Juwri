<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Builder;

use Illuminate\Support\Facades\Crypt;

/**
 * Render-time wiring for curated Builder.js page forms.
 *
 * cleanPage() keeps a `<form>` (marking it `data-mb-form`) but strips its action/
 * method — an operator can never choose where the form posts. Here, at render, each
 * marked form is pointed at the platform submit route and given the hidden fields
 * the endpoint needs:
 *   - `_mbctx`  an ENCRYPTED, tamper-proof context (which page/surface + where to
 *               notify) so the submit handler trusts nothing from the visible form,
 *   - `website` a honeypot (bots fill it; humans never see it),
 *   - `_mbts`   a render timestamp (a sub-2s submit is a bot).
 *
 * No CSRF token is injected: the route is CSRF-exempt (a public, no-auth contact
 * form) so pages stay full-page-cacheable; abuse is handled by honeypot + timing +
 * per-IP rate limiting at the endpoint.
 */
class BuilderForms
{
    /**
     * @param  array{surface?:string,id?:int|string|null,to?:string,label?:string,url?:string}  $ctx
     */
    public static function prepare(string $html, array $ctx = []): string
    {
        if ($ctx === [] || ! str_contains($html, 'data-mb-form')) {
            return $html;
        }

        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"><!DOCTYPE html><html><body><div id="__mbfroot__">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__mbfroot__');
        if (! $root) {
            return $html;
        }

        $token = self::encodeContext($ctx);
        $action = route('builder.form.submit');
        $forms = iterator_to_array($doc->getElementsByTagName('form'));
        foreach ($forms as $form) {
            if (! $form->hasAttribute('data-mb-form')) {
                continue;
            }
            $form->setAttribute('action', $action);
            $form->setAttribute('method', 'post');

            $first = $form->firstChild;
            $form->insertBefore(self::hidden($doc, '_mbctx', $token), $first);
            $form->insertBefore(self::hidden($doc, '_mbts', (string) time()), $first);
            $form->insertBefore(self::honeypot($doc), $first);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * The same hidden fields as prepare() injects, as an HTML string — for trusted
     * templates (locked templates) that place a `{{form:fields}}` placeholder instead
     * of going through the DOM pass.
     *
     * @param  array{surface?:string,id?:int|string|null,to?:string,label?:string,url?:string}  $ctx
     */
    public static function fieldsHtml(array $ctx): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<input type="hidden" name="_mbctx" value="'.$e(self::encodeContext($ctx)).'">'
            .'<input type="hidden" name="_mbts" value="'.time().'">'
            .'<div aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;">'
            .'<input type="text" name="website" tabindex="-1" autocomplete="off"></div>';
    }

    /**
     * Build the encrypted, tamper-proof form context passed back on submit.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function encodeContext(array $ctx): string
    {
        return Crypt::encryptString((string) json_encode([
            's' => substr((string) ($ctx['surface'] ?? ''), 0, 16),
            'id' => $ctx['id'] ?? null,
            'to' => (string) ($ctx['to'] ?? ''),
            'label' => substr((string) ($ctx['label'] ?? ''), 0, 120),
            'url' => substr((string) ($ctx['url'] ?? ''), 0, 255),
        ]));
    }

    private static function hidden(\DOMDocument $doc, string $name, string $value): \DOMElement
    {
        $el = $doc->createElement('input');
        $el->setAttribute('type', 'hidden');
        $el->setAttribute('name', $name);
        $el->setAttribute('value', $value);

        return $el;
    }

    private static function honeypot(\DOMDocument $doc): \DOMElement
    {
        // Off-screen, aria-hidden, not tabbable, autocomplete off — invisible to humans.
        $wrap = $doc->createElement('div');
        $wrap->setAttribute('aria-hidden', 'true');
        $wrap->setAttribute('style', 'position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;');
        $input = $doc->createElement('input');
        $input->setAttribute('type', 'text');
        $input->setAttribute('name', 'website');
        $input->setAttribute('tabindex', '-1');
        $input->setAttribute('autocomplete', 'off');
        $wrap->appendChild($input);

        return $wrap;
    }
}
