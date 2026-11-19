<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/**
 * Deterministic exact-name selection (port of src/lib/ai-exact-selection.ts):
 * when the visitor explicitly asks to add exactly ONE catalog assay named in
 * full, the item is resolved from trusted catalog data (its real ID, lab and
 * section). Model-generated IDs are never consulted for that operation. Any
 * uncertainty is refused so the existing clarification path runs.
 */
final class ExactSelection
{
    /**
     * @return array{ok: true, selection: array{labId: string, labType: string, subLabId: ?string, sectionKey: string, parameterId: string, name: string}}|array{ok: false, reason: string}
     */
    public static function resolve(string $message, array $catalog): array
    {
        $refuse = fn (string $reason) => ['ok' => false, 'reason' => $reason];
        $action = ActionParser::parse($message);
        if ($action['negated'] || $action['hardwareOff']) {
            return $refuse('conflicting_action');
        }
        $kinds = array_column($action['actions'], 'kind');
        if ($kinds === [] || array_diff($kinds, ['add']) !== []) {
            return $refuse($kinds !== [] ? 'conflicting_action' : 'no_add_intent');
        }
        if (count($kinds) !== 1) {
            return $refuse('conflicting_action');
        }
        $items = [];
        foreach ($catalog['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                foreach ($s['parameters'] as $p) {
                    $items[] = ['kind' => 'parameter', 'l' => $l, 's' => $s, 'id' => $p['id'], 'name' => $p['name']];
                }
                foreach ($s['hardware'] as $h) {
                    $items[] = ['kind' => 'hardware', 'l' => $l, 's' => $s, 'id' => $h['id'], 'name' => $h['name']];
                }
            }
        }
        $named = CatalogMatching::exactlyNamedItems($message, array_column($items, 'name'));
        if ($named === []) {
            return $refuse('no_exact_name');
        }
        if (count($named) > 1) {
            return $refuse('multiple_names');
        }
        $name = $named[0];
        $wanted = Js::trim(CatalogMatching::normalizeLabText($name));
        $matches = array_values(array_filter($items, fn (array $i) => Js::trim(CatalogMatching::normalizeLabText($i['name'])) === $wanted));
        // Same name in several sections/labs (or as both assay and equipment) is never resolved by picking one.
        if (count(array_unique(array_map(fn (array $m) => "{$m['kind']}:{$m['id']}", $matches))) !== 1) {
            return $refuse('not_unique');
        }
        $item = $matches[0];
        // Equipment additions keep the existing explicit-authorization path.
        if ($item['kind'] !== 'parameter' || $item['s']['hardwareOnly']) {
            return $refuse('not_assay');
        }
        $rest = Js::replaceAll(CatalogMatching::normalizeLabText($message), $wanted, ' ');
        foreach (JsRegex::all('[\p{L}\p{N}]+', 'u', $rest) as $word) {
            // Filler may surround the named item; anything else is "extra content".
            if (! Data::has('FILLER', $word)) {
                return $refuse('extra_content');
            }
        }

        return ['ok' => true, 'selection' => ['labId' => $item['l']['labId'], 'labType' => $item['l']['labType'], 'subLabId' => $item['s']['subLabId'],
            'sectionKey' => $item['s']['key'], 'parameterId' => $item['id'], 'name' => $item['name']]];
    }
}
