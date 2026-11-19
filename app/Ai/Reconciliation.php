<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;
use RuntimeException;

/**
 * Turns a model proposal into saved state and the visible reply (port of
 * src/lib/ai-state-reconciliation.ts). The model only proposes: what is saved
 * is decided here from the visitor's own words and the published catalog, and
 * every visible sentence is fixed wording plus catalog names.
 */
final class Reconciliation
{
    private const MAX_OPTIONS = 12;

    private const PENDING_EN = 'Annual test volume noted, not yet saved';

    private const PENDING_AR = 'تم تسجيل إجمالي الفحوص السنوي، ولم يُحفظ بعد';

    public const CONFIRM_EN = 'Would you like to continue with this configuration, or change anything first?';

    public const CONFIRM_AR = 'هل تريد متابعة هذا الإعداد أم تغيير شيء؟';

    private const VOLUME_EN = 'What is the total annual test volume for this section?';

    private const VOLUME_AR = 'ما إجمالي الفحوص السنوي لهذا القسم؟';

    private const ALL = '\ball\b|جميع|كل';

    /** The selection as stored: ids, volume and equipment choice only. */
    public static function rawState(array $state): array
    {
        return ['labs' => array_map(fn (array $l) => ['labId' => $l['labId'], 'labType' => $l['labType'], 'sections' => array_map(fn (array $s) => [
            'subLabId' => $s['subLabId'], 'annualTestVolume' => $s['annualTestVolume'], 'includeHardware' => $s['includeHardware'],
            'selectedHardwareIds' => array_values($s['selectedHardwareIds']), 'selectedParameterIds' => array_values($s['selectedParameterIds']),
        ], $l['sections'])], $state['labs'])];
    }

    /** Reads only the canonical pending-volume line of the last accepted reply. */
    public static function pendingVolumeFrom(string $reference): ?float
    {
        foreach (explode("\n\n", $reference) as $line) {
            $m = JsRegex::exec('^(?:'.self::PENDING_EN.'|'.self::PENDING_AR.'): (\d{1,9})\.$', '', $line);
            if ($m !== null) {
                return Js::toNumber($m[1]);
            }
        }

        return null;
    }

    /** Final response type derives from validated state and reconciliation, never the model enum. */
    public static function deriveResponseKind(array $r, array $turn): string
    {
        if ($turn['scope'] !== 'laboratory' || $turn['response']['kind'] === 'safe_refusal') {
            return 'safe_refusal';
        }
        if ($turn['response']['kind'] === 'unavailable_item') {
            return 'unavailable_item';
        }
        if (array_diff($r['options'], self::savedNames($r['state'])) !== []) {
            return 'catalog_options';
        }
        if ($r['changed'] || (count($r['state']['labs']) > 0 && $r['issues'] === [])) {
            return 'configuration_summary';
        }

        return 'clarification';
    }

    /** The explicit Review configuration action confirms this handoff, not pricing. */
    public static function confirmedHandoff(array $state): array
    {
        if (! $state['complete'] || $state['labs'] === [] || $state['rejected'] !== []) {
            throw new RuntimeException('INCOMPLETE_CONFIGURATION');
        }

        return SelectionValidation::toCalculatorSelection($state);
    }

    /**
     * Deterministic proposal for an explicit add of exactly one assay named in
     * full: prior saved state plus that trusted catalog item. The model's
     * selection and IDs are replaced (never merged), so invalid or unrelated
     * model IDs can neither block nor extend it.
     */
    private static function exactSelectionTurn(array $prior, array $turn, array $catalog, string $message): ?array
    {
        $exact = ExactSelection::resolve($message, $catalog);
        if (! $exact['ok']) {
            return null;
        }
        ['labId' => $labId, 'labType' => $labType, 'subLabId' => $subLabId, 'parameterId' => $parameterId] = $exact['selection'];
        $selection = self::rawState($prior);
        $li = self::indexOf($selection['labs'], 'labId', $labId);
        if ($li === null) {
            $selection['labs'][] = ['labId' => $labId, 'labType' => $labType, 'sections' => []];
            $li = count($selection['labs']) - 1;
        }
        $si = self::indexOf($selection['labs'][$li]['sections'], 'subLabId', $subLabId);
        if ($si === null) {
            $selection['labs'][$li]['sections'][] = self::emptySection($subLabId);
            $si = count($selection['labs'][$li]['sections']) - 1;
        }
        if (! in_array($parameterId, $selection['labs'][$li]['sections'][$si]['selectedParameterIds'], true)) {
            $selection['labs'][$li]['sections'][$si]['selectedParameterIds'][] = $parameterId;
        }
        $turn['selection'] = $selection;
        $turn['response'] = ['kind' => 'configuration_summary', 'parameterIds' => [], 'hardwareIds' => []] + $turn['response'];

        return $turn;
    }

    /**
     * @return array{state: array, issues: list<string>, options: list<string>, changed: bool, pendingVolume: ?float}
     */
    public static function reconcileTurn(array $prior, array $turn, array $catalog, string $message, string $trustedReference = ''): array
    {
        $raw = self::rawState($prior);
        $issues = [];
        $options = [];
        $unchanged = fn (array $issues, array $options = [], ?float $pendingVolume = null) => [
            'state' => $prior, 'issues' => $issues, 'options' => $options, 'changed' => false, 'pendingVolume' => $pendingVolume,
        ];
        if ($turn['scope'] !== 'laboratory' || $turn['response']['kind'] === 'safe_refusal') {
            return $unchanged([]);
        }
        $turn = self::exactSelectionTurn($prior, $turn, $catalog, $message) ?? $turn;
        if ($turn['response']['kind'] === 'unavailable_item') {
            return $unchanged(['unavailable']);
        }
        $action = ActionParser::parse($message);
        $text = $action['text'];
        $actions = $action['actions'];
        if ($action['negated']) {
            return $unchanged(['intent']);
        }
        $norm = CatalogMatching::normalizeLabText(...);
        $terms = CatalogMatching::laboratoryTerms(...);
        $volumeOf = VolumeParser::parse(...);
        // Only an explicit short reference uses the last accepted, canonical response.
        // Never concatenate abandoned user requests to authorize an addition.
        $reference = JsRegex::test('^(yes|yes please|add (that|those|it|them)|include (that|those|it|them)|نعم|اضفها|اضف ذلك|اضفهم)[.!\s]*$', '', $text);
        // Resolve only a single canonical option, never every item in a previous
        // configuration summary. Multiple displayed options remain ambiguous.
        $optionText = '';
        foreach (explode("\n\n", $trustedReference) as $line) {
            if (str_starts_with($line, 'Catalog options, not confirmed additions:') || str_starts_with($line, 'خيارات متاحة في الكتالوج، وليست إضافات مؤكدة:')) {
                $at = strpos($line, ': ');
                $optionText = JsRegex::replace('\.$', '', $at === false ? '' : substr($line, $at + 2), '');
                break;
            }
        }
        $singleOption = $optionText !== '' && ! str_contains($optionText, ';') ? $optionText : '';
        $confirmsSavedState = $prior['complete'] && $action['confirmation']
            && (str_ends_with($trustedReference, self::CONFIRM_EN) || str_ends_with($trustedReference, self::CONFIRM_AR));
        if (! $confirmsSavedState && $reference && $optionText !== '' && $singleOption === '') {
            $issues[] = 'ambiguous';
        }
        $intent = ! $confirmsSavedState && $reference && $singleOption !== '' ? "{$message}\n{$singleOption}" : $message;
        // An invalid model proposal turns the whole turn into read-only discovery:
        // options are listed from trusted catalog matching, nothing is added.
        $proposalInvalid = count(SelectionValidation::validate($turn['selection'], $catalog)['rejected']) > 0;
        $discovery = $proposalInvalid || $action['discovery'] || (! $action['explicitSelection'] && ! $reference && $turn['response']['kind'] === 'catalog_options');
        $removalActions = array_values(array_filter($actions, fn (array $a) => $a['kind'] === 'remove' || $a['kind'] === 'replace'));
        $remove = $removalActions !== [];
        $removalText = implode("\n", array_map(fn (array $a) => $a['kind'] === 'replace'
            ? JsRegex::split('\bwith\b|\bby\b|بـ|\sب(?=[؀-ۿA-Z])', 'i', $a['target'])[0] ?? ''
            : $a['target'], $removalActions));
        $replacing = in_array('replace', array_column($removalActions, 'kind'), true);
        $messageTerms = $terms($message);
        $namedLabs = array_column(array_filter($catalog['labs'], fn (array $l) => str_contains($text, $norm($l['labName'])) || in_array($norm($l['labName']), $messageTerms, true)), 'labId');
        $labAllowed = fn (string $labId) => $namedLabs === [] || in_array($labId, $namedLabs, true);
        $allowedLabs = array_values(array_filter($catalog['labs'], fn (array $l) => $labAllowed($l['labId'])));
        $priorSectionCount = array_sum(array_map(fn (array $l) => count($l['sections']), $prior['labs']));
        $annualFollowup = JsRegex::test('^(?:\d[\d,.]*)$', '', $text)
            && (str_ends_with($trustedReference, self::VOLUME_EN) || str_ends_with($trustedReference, self::VOLUME_AR))
            && $priorSectionCount === 1;
        // A volume stated during a clarification survives only through the canonical
        // pending line of the last accepted reply, and only if this message states none.
        $pending = self::pendingVolumeFrom($trustedReference);
        $volumeMessage = $annualFollowup ? "{$message} per year" : $message;
        $allNames = [];
        foreach ($catalog['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                array_push($allNames, ...array_column($s['parameters'], 'name'), ...array_column($s['hardware'], 'name'));
            }
        }
        // Pack sizes inside an exact catalog name (e.g. "4 x 100 Tests") are never a
        // volume; strip them only when deciding whether a pending volume applies.
        $withoutNames = $volumeMessage;
        if ($pending !== null) {
            $normalizedNames = array_map($norm, $allNames);
            usort($normalizedNames, fn (string $a, string $b) => Js::length($b) <=> Js::length($a));
            $withoutNames = $norm($volumeMessage);
            foreach ($normalizedNames as $name) {
                $withoutNames = Js::replaceAll($withoutNames, $name, ' ');
            }
        }
        $stated = $volumeOf($withoutNames);
        $volume = $pending !== null && $stated['value'] === null && ! $stated['incompatible'] ? ['value' => $pending] + $stated : $stated;
        $unsupportedPeriod = JsRegex::test('per day|daily|per week|weekly|يومي|اسبوع|في اليوم', '', $text);
        if ($volume['incompatible']) {
            $issues[] = 'volume';
        }
        // An item named in full takes precedence over keyword discovery: only the
        // exactly named items are candidates/options for this request text.
        $exactNames = CatalogMatching::exactlyNamedItems($intent, $allNames);
        $matches = fn (string $request, string $name) => $request === $intent && $exactNames !== []
            ? in_array($name, $exactNames, true) : CatalogMatching::matchesCatalogName($request, $name);
        $scopedCandidates = CatalogMatching::targetedCatalog($catalog, $intent, []);
        $intentTerms = $terms($intent);
        $monitoring = in_array('therapeutic drug monitoring', $intentTerms, true);
        $explicitAnalyte = array_intersect($intentTerms, ['digoxin', 'vancomycin']) !== [];
        $matchingParameters = [];
        $hardwareMatches = [];
        foreach ($allowedLabs as $l) {
            foreach ($l['sections'] as $s) {
                foreach ($s['parameters'] as $p) {
                    if ($matches($intent, $p['name']) || ($monitoring && ! $explicitAnalyte && isset($scopedCandidates['parameterIds'][$p['id']]))) {
                        $matchingParameters[] = $p;
                    }
                }
                foreach ($s['hardware'] as $h) {
                    if ($matches($intent, $h['name'])) {
                        $hardwareMatches[] = $h;
                    }
                }
            }
        }
        $adds = array_intersect(array_column($actions, 'kind'), ['add', 'replace']) !== [];
        // A full name is not a unique identity when several catalog IDs share it.
        // Discard the entire addition/replacement, including model-chosen IDs, so
        // clarification cannot silently add duplicates or remove the old selection.
        $duplicateNames = array_values(array_filter($exactNames, fn (string $name) => count(array_unique(array_column(
            array_filter($matchingParameters, fn (array $p) => $norm($p['name']) === $norm($name)), 'id'))) > 1));
        if ($duplicateNames !== [] && $adds) {
            return $unchanged(array_values(array_unique([...$issues, 'ambiguous'])), array_slice($duplicateNames, 0, self::MAX_OPTIONS),
                $volume['value'] !== null && ! $volume['incompatible'] ? $volume['value'] : null);
        }
        $exactHardware = array_values(array_filter($hardwareMatches, fn (array $h) => str_contains($text, $norm($h['name']))));
        $matchingHardware = $exactHardware !== [] ? $exactHardware : $hardwareMatches;
        // Only split conjunctions that introduce another explicit throughput clause;
        // a list of assays followed by one section total must stay together.
        $clauses = [];
        foreach (JsRegex::split(';|\n', '', $volumeMessage) as $clause) {
            $parts = JsRegex::split('\band\b|،', 'i', $clause);
            $withVolume = count(array_filter($parts, fn (string $p) => $volumeOf($p)['value'] !== null));
            foreach ($withVolume > 1 ? $parts : [$clause] as $part) {
                if (Js::trim($part) !== '') {
                    $clauses[] = Js::trim($part);
                }
            }
        }
        $clauseNames = fn (string $clause, string $labName, array $section) => CatalogMatching::sectionRequested($clause, $labName, $section)
            || self::any($section['parameters'], fn (array $p) => $matches($clause, $p['name']));
        $scopedVolume = function (string $labName, array $section) use ($clauses, $clauseNames, $volume, $volumeOf) {
            $matching = array_values(array_filter($clauses, fn (string $c) => $clauseNames($c, $labName, $section)));
            // Clause-specific volumes allow different sections/labs, never separate
            // per-assay volumes within the same section (which still require clarification).
            if (count($matching) !== 1) {
                return $volume;
            }
            $candidate = $volumeOf($matching[0]);

            return $candidate['value'] !== null ? $candidate : $volume;
        };
        // Detect material product/platform/technology ambiguity across sections and labs,
        // not just within the proposed section. Multi-analyte requests group separately.
        $alternativesFor = function (string $name, array $items) use ($terms, $matches) {
            $nameTerms = $terms($name);

            return array_values(array_filter($items, fn (array $p) => $nameTerms !== []
                ? array_intersect($terms($p['name']), $nameTerms) !== [] : $matches($name, $p['name'])));
        };
        // A proposal containing ANY invalid catalog ID is discarded entirely (valid
        // IDs in it are never certified either). The visitor's own words are still
        // resolved deterministically against trusted catalog data below. Prior
        // saved selections are never removed by this path.
        if ($proposalInvalid) {
            $issues[] = 'invalid_selection';
            $turn['selection'] = ['labs' => []];
            $turn['response'] = ['parameterIds' => [], 'hardwareIds' => []] + $turn['response'];
        }
        foreach ([['parameterIds', 'parameters'], ['hardwareIds', 'hardware']] as [$idsKey, $itemsKey]) {
            foreach ($turn['response'][$idsKey] as $id) {
                foreach ($catalog['labs'] as $l) {
                    foreach ($l['sections'] as $s) {
                        $item = self::find($s[$itemsKey], 'id', $id);
                        if ($item !== null && $matches($intent, $item['name'])) {
                            $options[] = $item['name'];
                        }
                    }
                }
            }
        }
        if ($discovery || $turn['response']['kind'] === 'catalog_options' || $monitoring) {
            array_push($options, ...array_column($matchingParameters, 'name'), ...array_column($matchingHardware, 'name'));
        }

        // Explicit named lab/section removal, including dependent selections.
        foreach ($raw['labs'] as $li => $l) {
            $catLab = self::find($catalog['labs'], 'labId', $l['labId']);
            if ($catLab === null) {
                continue;
            }
            $namedSection = self::any($catLab['sections'], fn (array $s) => CatalogMatching::sectionRequested($removalText, $catLab['labName'], $s));
            if ($remove && ! $namedSection && str_contains($removalText, $norm($catLab['labName'])) && JsRegex::test('\b(lab|laboratory)\b|مختبر', '', $removalText)) {
                $raw['labs'][$li]['sections'] = [];
            } else {
                $raw['labs'][$li]['sections'] = array_values(array_filter($l['sections'], function (array $s) use ($catLab, $remove, $labAllowed, $l, $removalText) {
                    $cs = self::find($catLab['sections'], 'subLabId', $s['subLabId']);

                    return ! ($remove && $labAllowed($l['labId']) && $cs !== null
                        && CatalogMatching::sectionRequested($removalText, $catLab['labName'], $cs) && JsRegex::test('\bsection\b|قسم', '', $removalText));
                }));
            }
        }

        $allInText = JsRegex::test(self::ALL, '', $text);
        $hardwareScopeNamed = self::any($catalog['labs'], fn (array $lab) => self::any($lab['sections'], fn (array $section) => CatalogMatching::sectionRequested($intent, $lab['labName'], $section)));
        $notKept = fn (string $kind) => $kind !== 'keep';
        $isKept = fn (string $kind) => $kind === 'keep';
        foreach ($allowedLabs as $l) {
            $namedSections = array_column(array_filter($l['sections'], fn (array $c) => CatalogMatching::sectionRequested($intent, $l['labName'], $c)), 'key');
            foreach ($l['sections'] as $s) {
                if ($namedSections !== [] && ! in_array($s['key'], $namedSections, true)) {
                    continue;
                }
                $li = self::indexOf($raw['labs'], 'labId', $l['labId']);
                $si = $li === null ? null : self::indexOf($raw['labs'][$li]['sections'], 'subLabId', $s['subLabId']);
                $proposedLab = self::find($turn['selection']['labs'], 'labId', $l['labId']);
                $proposed = $proposedLab === null ? null : self::find($proposedLab['sections'], 'subLabId', $s['subLabId']);
                $scope = CatalogMatching::sectionRequested($intent, $l['labName'], $s);
                $selectAll = $allInText && $scope;
                $candidates = array_values(array_filter($s['parameters'], fn (array $p) => $matches($intent, $p['name']) || $selectAll));
                $exactParameters = array_values(array_filter($candidates, fn (array $p) => str_contains($text, $norm($p['name']))));
                $exactIds = array_column($exactParameters, 'id');
                $params = array_values(array_filter($candidates, fn (array $p) => $selectAll || in_array($p['id'], $exactIds, true)
                    || ! self::any($exactParameters, fn (array $exact) => array_intersect($terms($exact['name']), $terms($p['name'])) !== [])));
                $paramIds = array_column($params, 'id');
                $hardware = array_values(array_filter($s['hardware'], fn (array $h) => self::find($matchingHardware, 'id', $h['id']) !== null));
                $singlePriorVolume = $priorSectionCount === 1 && $si !== null && ($annualFollowup || JsRegex::test('change|volume|اجعل|غير|حجم', '', $text));
                $touches = fn (array $a) => CatalogMatching::sectionRequested($a['target'], $l['labName'], $s)
                    || self::any($params, fn (array $p) => $matches($a['target'], $p['name']));
                $keepOnly = self::any($actions, fn (array $a) => $isKept($a['kind']) && $touches($a))
                    && ! self::any($actions, fn (array $a) => $notKept($a['kind']) && $touches($a));
                if ($keepOnly) {
                    continue;
                }
                $relevant = $scope || $params !== [] || $hardware !== [] || $singlePriorVolume;
                $thisVolume = $unsupportedPeriod ? ['value' => null, 'incompatible' => true] : $scopedVolume($l['labName'], $s);
                if ($si === null && $relevant && (! $remove || $replacing) && ! $discovery
                    && ($proposed !== null || ($action['explicitSelection'] && ($params !== [] || $hardware !== [] || count($turn['selection']['labs']) === 0)))) {
                    if ($li === null) {
                        $raw['labs'][] = ['labId' => $l['labId'], 'labType' => $l['labType'], 'sections' => []];
                        $li = count($raw['labs']) - 1;
                    }
                    $raw['labs'][$li]['sections'][] = self::emptySection($s['subLabId']);
                    $si = count($raw['labs'][$li]['sections']) - 1;
                }
                if ($si === null) {
                    continue;
                }
                $target = $raw['labs'][$li]['sections'][$si];
                // Omission never implies removal. Only an explicit named item can be removed.
                if ($remove) {
                    $removalMatches = array_values(array_filter($s['parameters'], fn (array $p) => $matches($removalText, $p['name'])));
                    $exactRemovals = array_values(array_filter($removalMatches, fn (array $p) => str_contains($removalText, $norm($p['name']))));
                    $safeRemovals = array_column($exactRemovals !== [] ? $exactRemovals : (count($removalMatches) === 1 ? $removalMatches : []), 'id');
                    if ($exactRemovals === [] && count($removalMatches) > 1) {
                        $issues[] = 'ambiguous';
                        array_push($options, ...array_column($removalMatches, 'name'));
                    }
                    $target['selectedParameterIds'] = array_values(array_filter($target['selectedParameterIds'], fn (string $id) => ! in_array($id, $safeRemovals, true)));
                    $target['selectedHardwareIds'] = array_values(array_filter($target['selectedHardwareIds'], function (string $id) use ($s, $removalText, $norm) {
                        $h = self::find($s['hardware'], 'id', $id);

                        return $h === null || ! str_contains($norm($removalText), $norm($h['name']));
                    }));
                }
                $effectiveHardwareOff = $action['hardwareOff'] && ($relevant || ! $hardwareScopeNamed);
                if ($effectiveHardwareOff) {
                    $target['includeHardware'] = false;
                    $target['selectedHardwareIds'] = [];
                }
                if (! $discovery && ($proposed !== null || $action['explicitSelection'] || $singlePriorVolume)) {
                    // Catalog matching, not the response enum or omitted model IDs, resolves
                    // explicit named intent. Every candidate still passes catalog validation.
                    $replacementSource = $replacing ? $removalText : '';
                    $parameterIds = array_values(array_unique([...($proposed['selectedParameterIds'] ?? []), ...($adds ? $paramIds : [])]));
                    foreach ($parameterIds as $id) {
                        if (in_array($id, $target['selectedParameterIds'], true)) {
                            continue;
                        }
                        $item = self::find($s['parameters'], 'id', $id);
                        if ($item !== null && str_contains($replacementSource, $norm($item['name']))) {
                            continue;
                        }
                        // Full-state proposals often still contain the item just removed. Do not
                        // let that omission/error reverse the visitor's explicit instruction.
                        if ($remove && ! $replacing && $item !== null && $matches($removalText, $item['name'])) {
                            continue;
                        }
                        if ($item === null || ! in_array($id, $paramIds, true)) {
                            if (self::find($candidates, 'id', $id) === null) {
                                $issues[] = 'intent';
                            }

                            continue;
                        }
                        $exact = str_contains($norm($intent), $norm($item['name']));
                        // Platform/pack/technology variants are NEVER silently chosen.
                        $alternatives = $alternativesFor($item['name'], $matchingParameters);
                        if (count($alternatives) > 1 && ! $exact && ! ($allInText && $scope)) {
                            $issues[] = 'ambiguous';
                            array_push($options, ...array_column($alternatives, 'name'));

                            continue;
                        }
                        $target['selectedParameterIds'][] = $id;
                    }
                    $namesEquipment = $adds && JsRegex::test('\b(equipment|hardware|instrument|analyzer|include)\b|اجهز|معدات|جهاز', '', $text);
                    $hardwareIds = array_values(array_unique([...($proposed['selectedHardwareIds'] ?? []),
                        ...($namesEquipment ? array_column(array_filter($matchingHardware, fn (array $h) => self::find($s['hardware'], 'id', $h['id']) !== null), 'id') : [])]));
                    if (! $effectiveHardwareOff && (($proposed['includeHardware'] ?? false) || $s['hardwareOnly'] || $action['explicitSelection'])) {
                        foreach ($hardwareIds as $id) {
                            if (in_array($id, $target['selectedHardwareIds'], true)) {
                                continue;
                            }
                            $item = self::find($s['hardware'], 'id', $id);
                            if ($item !== null && str_contains($replacementSource, $norm($item['name']))) {
                                continue;
                            }
                            if ($remove && ! $replacing && $item !== null && str_contains($removalText, $norm($item['name']))) {
                                continue;
                            }
                            if ($item === null || self::find($hardware, 'id', $id) === null) {
                                $issues[] = 'intent';

                                continue;
                            }
                            if (count($matchingHardware) > 1 && ! str_contains($norm($intent), $norm($item['name']))) {
                                $issues[] = 'ambiguous';
                                array_push($options, ...array_column($matchingHardware, 'name'));

                                continue;
                            }
                            $target['selectedHardwareIds'][] = $id;
                            $target['includeHardware'] = true;
                        }
                    }
                    // Never trust model-created numeric conversions. Bind to the visitor's
                    // explicit annual/monthly volume only, and only one unambiguous section.
                    if (! $s['hardwareOnly'] && $thisVolume['value'] !== null && $relevant) {
                        $volumeIntent = $intent;
                        foreach ($clauses as $c) {
                            if ($volumeOf($c)['value'] === $thisVolume['value'] && $clauseNames($c, $l['labName'], $s)) {
                                $volumeIntent = $c;
                                break;
                            }
                        }
                        $relevantSections = 0;
                        foreach ($allowedLabs as $lab) {
                            foreach ($lab['sections'] as $c) {
                                if (! $clauseNames($volumeIntent, $lab['labName'], $c)) {
                                    continue;
                                }
                                $named = fn (array $a) => CatalogMatching::sectionRequested($a['target'], '', $c);
                                if (! self::any($actions, fn (array $a) => $isKept($a['kind']) && $named($a))
                                    || self::any($actions, fn (array $a) => $notKept($a['kind']) && $named($a))) {
                                    $relevantSections++;
                                }
                            }
                        }
                        // Several per-assay clauses for this one section are incompatible even
                        // if each clause has an individually valid annual number.
                        $sameSectionVolumes = count(array_filter($clauses, fn (string $c) => $volumeOf($c)['value'] !== null && $clauseNames($c, $l['labName'], $s)));
                        if ($sameSectionVolumes > 1) {
                            $issues[] = 'volume';
                        } elseif ($relevantSections === 1 || $singlePriorVolume) {
                            $target['annualTestVolume'] = $thisVolume['value'];
                        } else {
                            $issues[] = 'volume';
                        }
                    } elseif (! $s['hardwareOnly'] && $proposed !== null
                        && Js::toNumber($proposed['annualTestVolume']) !== Js::toNumber($target['annualTestVolume']) && ! $action['confirmation']) {
                        $issues[] = 'volume';
                    }
                }
                if ($proposed === null && ! $discovery && ! $s['hardwareOnly'] && $thisVolume['value'] !== null && $singlePriorVolume) {
                    $target['annualTestVolume'] = $thisVolume['value'];
                }
                $raw['labs'][$li]['sections'][$si] = $target;
            }
        }
        $raw['labs'] = array_values(array_filter($raw['labs'], fn (array $l) => count($l['sections']) > 0));
        // An invalid proposal never mutates saved state (no additions, removals or
        // equipment changes); it can only produce options and pending context.
        $state = $proposalInvalid ? $prior : SelectionValidation::validate($raw, $catalog);
        $savedVolumes = [];
        $volumeSaved = false;
        foreach ($state['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                $savedVolumes[] = (float) $s['annualTestVolume'];
                $volumeSaved = $volumeSaved || ($volume['value'] !== null && (float) $s['annualTestVolume'] === $volume['value'] && ! $s['hardwareOnly']);
            }
        }
        // Multiple explicit volumes across distinct sections are compatible when all
        // have been reconciled exactly; a global parse alone cannot establish that.
        if ($volume['incompatible'] && count($clauses) > 1) {
            $explicit = array_values(array_filter(array_map(fn (string $c) => $volumeOf($c)['value'], $clauses), fn (?float $v) => $v !== null));
            if (count($explicit) > 1 && array_diff($explicit, $savedVolumes) === []) {
                $index = array_search('volume', $issues, true);
                if ($index !== false) {
                    array_splice($issues, $index, 1);
                }
            }
        }
        $changed = Js::stringify(self::rawState($state)) !== Js::stringify(self::rawState($prior));
        $finalOptions = array_slice(array_values(array_unique($options)), 0, self::MAX_OPTIONS);
        // A stated volume not bound to any section stays pending while options are open.
        $pendingVolume = $volume['value'] !== null && ! $volume['incompatible'] && ! $volumeSaved && $finalOptions !== [] ? $volume['value'] : null;

        return ['state' => $state, 'issues' => array_values(array_unique($issues)), 'options' => $finalOptions, 'changed' => $changed, 'pendingVolume' => $pendingVolume];
    }

    public static function hasConfirmedConfiguration(array $prior, array $next, string $message, string $lastReply): bool
    {
        if (! $prior['complete'] || ! $next['complete'] || Js::stringify(self::rawState($prior)) !== Js::stringify(self::rawState($next))) {
            return false;
        }
        if (! ActionParser::parse($message)['confirmation']) {
            return false;
        }

        return str_ends_with($lastReply, self::CONFIRM_EN) || str_ends_with($lastReply, self::CONFIRM_AR);
    }

    /** Entire visible answer is canonical, bounded EN/AR prose and catalog data. */
    public static function renderLaboratoryReply(array $prior, array $reconciliation, array $turn, string $lang): string
    {
        $ar = $lang === 'ar';
        $t = fn (string $arabic, string $english) => $ar ? $arabic : $english;
        $state = $reconciliation['state'];
        $lines = [];
        if ($turn['response']['kind'] === 'safe_refusal' || $turn['scope'] !== 'laboratory') {
            return $t('يمكنني المساعدة في إعداد المختبر فقط.', 'I can help with laboratory configuration only.');
        }
        $before = [];
        foreach ($prior['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                $before[$s['key']] = $s;
            }
        }
        $after = [];
        $none = $t('لا يوجد', 'none');
        foreach ($state['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                $after[$s['key']] = true;
                $p = $before[$s['key']] ?? null;
                if ($p === null) {
                    $lines[] = "{$t('القسم المحدد', 'Selected section')}: {$l['labName']} — {$s['sectionName']}.";
                }
                $addedParams = array_values(array_diff($s['parameterNames'], $p['parameterNames'] ?? []));
                $removedParams = array_values(array_diff($p['parameterNames'] ?? [], $s['parameterNames']));
                $addedHardware = array_values(array_diff($s['hardwareNames'], $p['hardwareNames'] ?? []));
                $removedHardware = array_values(array_diff($p['hardwareNames'] ?? [], $s['hardwareNames']));
                if ($addedParams !== []) {
                    $lines[] = "{$t('تمت إضافة الفحوص من الكتالوج', 'Added assays from the catalog')}: ".implode('; ', $addedParams).'.';
                }
                if ($removedParams !== []) {
                    $lines[] = "{$t('تمت إزالة الفحوص', 'Removed assays')}: ".implode('; ', $removedParams).'.';
                }
                if ($addedHardware !== []) {
                    $lines[] = "{$t('تم تضمين الأجهزة', 'Included equipment')}: ".implode('; ', $addedHardware).'.';
                }
                if ($removedHardware !== [] || (($p['includeHardware'] ?? false) && ! $s['includeHardware'])) {
                    $lines[] = "{$t('تم استبعاد الأجهزة', 'Excluded equipment')}: ".(implode('; ', $removedHardware) ?: $s['sectionName']).'.';
                }
                if (! $s['hardwareOnly'] && ($p === null || (float) $p['annualTestVolume'] !== (float) $s['annualTestVolume'])) {
                    $lines[] = "{$s['sectionName']} — {$t('إجمالي الفحوص السنوي للقسم', 'Section annual test volume')}: ".Js::number($s['annualTestVolume']).'.';
                }
            }
        }
        foreach ($before as $key => $s) {
            if (! isset($after[$key])) {
                $lines[] = "{$t('تمت إزالة القسم وعناصره', 'Removed section and its items')}: {$s['sectionName']}.";
            }
        }
        $pendingOptions = array_values(array_diff($reconciliation['options'], self::savedNames($state)));
        $issues = $reconciliation['issues'];
        if ($pendingOptions !== []) {
            $lines[] = "{$t('خيارات متاحة في الكتالوج، وليست إضافات مؤكدة', 'Catalog options, not confirmed additions')}: ".implode('; ', $pendingOptions).'.';
        }
        if (($reconciliation['pendingVolume'] ?? null) !== null) {
            $lines[] = $t(self::PENDING_AR, self::PENDING_EN).': '.Js::number($reconciliation['pendingVolume']).'.';
        }
        if ($turn['response']['kind'] === 'unavailable_item') {
            $lines[] = $t('لم يتم التحقق من تطابق دقيق للعنصر المطلوب؛ لم تتم إضافة بديل. يرجى تأكيد الاسم وتقنية الفحص.', 'No exact match for the requested item was validated; no substitute was added. Please confirm the name and assay technology.');
        }
        if (in_array('invalid_selection', $issues, true) && $pendingOptions === []) {
            $lines[] = $t('لم يتم العثور على عنصر مطابق في الكتالوج لهذا الطلب؛ لم تتم إضافة أي شيء.', 'No matching catalog item was found for this request; nothing was added.');
        }
        if (array_intersect($issues, ['intent', 'invalid_selection', 'ambiguous']) !== []) {
            $lines[] = $t('لم تتم إضافة العناصر غير الصالحة أو غير المؤكدة. يرجى تحديد الاسم الدقيق وخيار الجهاز أو تقنية الفحص.', 'Invalid or unconfirmed items were not added. Please confirm the exact catalog item, platform or assay technology.');
        }
        if (in_array('volume', $issues, true)) {
            $lines[] = $t('يرجى تأكيد إجمالي الفحوص السنوي لكل قسم. لا يمكن حفظ أحجام منفصلة لكل فحص؛ يوزع إجمالي القسم بالتساوي على الفحوص المحددة.', 'Please confirm one annual total per section. Separate per-assay volumes cannot be saved; the section total is distributed equally across selected assays.');
        }
        // Summary always reflects the exact effective state, including unchanged choices.
        foreach ($state['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                $lines[] = "{$l['labName']} — {$s['sectionName']}: {$t('الفحوص المحددة', 'Selected assays')} (".count($s['parameterNames']).'): '.(implode('; ', $s['parameterNames']) ?: $none).'.';
                if (! $s['hardwareOnly']) {
                    $lines[] = "{$t('إجمالي الفحوص السنوي للقسم', 'Section annual test volume')}: ".Js::number($s['annualTestVolume']).". {$t('يوزع بالتساوي على الفحوص المحددة', 'Distributed equally across selected assays')}.";
                }
                $lines[] = "{$t('الأجهزة المضمنة', 'Included equipment')}: ".(implode('; ', $s['hardwareNames']) ?: $none).'.';
            }
        }
        if ($lines === []) {
            $lines[] = $t('لم تتم إضافة أي عنصر إلى إعداد المختبر بعد.', 'No item has been added to the laboratory configuration yet.');
        }
        $questions = [
            'none' => $t('ما متطلبات المختبر التالية؟', 'What other laboratory requirements do you have?'),
            'item' => $t('ما الفحص أو العنصر الدقيق الذي تريد تضمينه؟', 'Which exact assay or catalog item would you like to include?'),
            'section' => $t('ما القسم الذي تريد إعداده؟', 'Which laboratory section would you like to configure?'),
            'volume' => $t(self::VOLUME_AR, self::VOLUME_EN),
            'hardware' => $t('هل تريد تضمين أجهزة المختبر المتاحة؟', 'Do you want to include catalog laboratory equipment?'),
            'confirm' => $t(self::CONFIRM_AR, self::CONFIRM_EN),
            'laboratory' => $t('ما نوع المختبر الذي تريد إعداده؟', 'Which laboratory type would you like to configure?'),
        ];
        // Completion is a property of authoritative state, never a model enum.
        $kind = self::deriveResponseKind($reconciliation, $turn);
        $missing = fn (string $needle) => self::any($state['missingFields'], fn (string $m) => str_contains($m, $needle));
        $question = match (true) {
            $state['complete'] && $issues === [] => 'confirm',
            in_array('volume', $issues, true) || $missing('expected tests per year') => 'volume',
            $kind === 'catalog_options' || in_array('invalid_selection', $issues, true) => 'item',
            $missing('selection') => 'item',
            default => $turn['response']['question'],
        };
        $lines[] = $questions[$question];

        return implode("\n\n", $lines);
    }

    /** @return list<string> */
    private static function savedNames(array $state): array
    {
        $names = [];
        foreach ($state['labs'] as $l) {
            foreach ($l['sections'] as $s) {
                array_push($names, ...$s['parameterNames'], ...$s['hardwareNames']);
            }
        }

        return $names;
    }

    public static function emptySection(?string $subLabId): array
    {
        return ['subLabId' => $subLabId, 'annualTestVolume' => 0, 'includeHardware' => false, 'selectedHardwareIds' => [], 'selectedParameterIds' => []];
    }

    public static function find(array $items, string $key, mixed $value): ?array
    {
        foreach ($items as $item) {
            if (array_key_exists($key, $item) && $item[$key] === $value) {
                return $item;
            }
        }

        return null;
    }

    public static function indexOf(array $items, string $key, mixed $value): ?int
    {
        foreach ($items as $index => $item) {
            if (array_key_exists($key, $item) && $item[$key] === $value) {
                return $index;
            }
        }

        return null;
    }

    public static function any(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }
}
