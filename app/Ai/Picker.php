<?php

namespace App\Ai;

use App\Support\Js;

/**
 * Clickable choices for the AI assistant (port of src/lib/ai-picker.ts). Pure
 * and deterministic: options come only from the published safe catalog, a
 * click is applied to the saved selection without any model call, and the
 * result is revalidated exactly like a model proposal. No pricing exists in
 * any input here.
 *
 * Visitors may pick several laboratories (and several sections) at once. The
 * chosen laboratory order is the "plan": the assistant then walks the plan one
 * laboratory and one section at a time (equipment, tests, annual volume).
 */
final class Picker
{
    private const MAX_VOLUME = 1_000_000_000;

    public const MAX_PLAN_LABS = 10;

    private static function findLab(array $catalog, mixed $labId): ?array
    {
        return is_string($labId) ? Reconciliation::find($catalog['labs'], 'labId', $labId) : null;
    }

    private static function findSection(?array $lab, ?string $subLabId): ?array
    {
        if ($lab === null) {
            return null;
        }
        if ($lab['flat']) {
            return $subLabId === null ? ($lab['sections'][0] ?? null) : null;
        }

        return Reconciliation::find($lab['sections'], 'subLabId', $subLabId);
    }

    private static function savedSection(array $state, string $labId, ?string $subLabId): ?array
    {
        $lab = Reconciliation::find($state['labs'], 'labId', $labId);

        return $lab === null ? null : Reconciliation::find($lab['sections'], 'subLabId', $subLabId);
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return Js::grouped($n).' '.($n === 1 ? $one : $many);
    }

    private static function t(string $lang, string $en, string $ar): string
    {
        return $lang === 'ar' ? $ar : $en;
    }

    /** @param  list<string>  $names */
    private static function list(string $lang, array $names): string
    {
        return implode($lang === 'ar' ? '، ' : ', ', $names);
    }

    /**
     * Only published laboratory ids, de-duplicated, in the visitor's order.
     *
     * @return list<string>
     */
    public static function sanitizePlan(mixed $plan, array $catalog): array
    {
        if (! is_array($plan) || ! array_is_list($plan)) {
            return [];
        }
        $ids = array_filter($plan, fn (mixed $id) => self::findLab($catalog, $id) !== null);

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_PLAN_LABS);
    }

    private static function sectionHint(array $section, string $lang): string
    {
        if ($section['hardwareOnly']) {
            return self::t($lang, 'Equipment only', 'معدات فقط');
        }
        $count = count($section['parameters']);
        $tests = self::t($lang, self::plural($count, 'test', 'tests'), "{$count} فحص");
        if (! $section['supportsHardware']) {
            return $tests;
        }
        $machines = count($section['hardware']);

        return self::t($lang, "{$tests} · ".self::plural($machines, 'machine', 'machines'), "{$tests} · {$machines} جهاز");
    }

    /**
     * Parameter ids that belong to the parameter groups of the chosen equipment.
     *
     * @param  list<string>  $hardwareIds
     * @return list<string>
     */
    public static function testsForHardware(array $section, array $hardwareIds): array
    {
        $groupIds = [];
        foreach ($section['hardware'] as $h) {
            if (in_array($h['id'], $hardwareIds, true) && $h['groupId']) {
                $groupIds[] = $h['groupId'];
            }
        }
        $ids = [];
        foreach ($section['parameters'] as $p) {
            if ($p['groupId'] && in_array($p['groupId'], $groupIds, true)) {
                $ids[] = $p['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function base(string $step, string $prompt): array
    {
        return [
            'step' => $step, 'prompt' => $prompt, 'labId' => null, 'subLabId' => null, 'multi' => false, 'options' => [],
            'hardwareNames' => [], 'currentVolume' => null, 'canAddSection' => false, 'canReview' => false, 'canSkipLab' => false,
        ];
    }

    private static function untouched(array $s): bool
    {
        return count($s['selectedHardwareIds']) === 0 && count($s['selectedParameterIds']) === 0 && $s['annualTestVolume'] <= 0;
    }

    private static function labPicker(array $state, array $catalog, string $lang): array
    {
        $picker = self::base('lab', count($state['labs']) > 0
            ? self::t($lang, 'Which other laboratories would you like to add? Pick as many as you need.', 'ما المختبرات الأخرى التي تريد إضافتها؟ اختر ما تحتاجه.')
            : self::t($lang, "Which laboratories are you planning? Pick as many as you need, and I'll set them up with you one at a time.", 'ما المختبرات التي تخطط لها؟ اختر ما تحتاجه وسأساعدك في إعدادها واحدًا تلو الآخر.'));
        $picker['multi'] = true;
        foreach ($catalog['labs'] as $lab) {
            $sections = count($lab['sections']);
            $picker['options'][] = [
                'id' => $lab['labId'],
                'label' => $lab['labName'],
                'hint' => $lab['flat'] ? self::sectionHint($lab['sections'][0], $lang)
                    : self::t($lang, self::plural($sections, 'section', 'sections'), "{$sections} أقسام"),
                'selected' => Reconciliation::find($state['labs'], 'labId', $lab['labId']) !== null,
            ];
        }

        return $picker;
    }

    private static function sectionPicker(array $state, array $lab, string $lang, string $intro, bool $canSkipLab): array
    {
        $picker = self::base('section', $intro.self::t($lang,
            "Which sections of {$lab['labName']} do you need? Pick as many as you like.",
            "ما الأقسام التي تحتاجها في {$lab['labName']}؟ اختر ما تريد."));
        $picker['labId'] = $lab['labId'];
        $picker['multi'] = true;
        $picker['canSkipLab'] = $canSkipLab;
        foreach ($lab['sections'] as $s) {
            $picker['options'][] = [
                'id' => $s['subLabId'] ?? '',
                'label' => $s['name'],
                'hint' => self::sectionHint($s, $lang),
                'selected' => self::savedSection($state, $lab['labId'], $s['subLabId']) !== null,
            ];
        }

        return $picker;
    }

    private static function hardwarePicker(array $state, array $lab, array $section, string $lang, string $intro = ''): array
    {
        $saved = self::savedSection($state, $lab['labId'], $section['subLabId']);
        $prompt = $section['hardwareOnly']
            ? self::t($lang, "Which equipment do you need for {$section['name']}?", "ما المعدات التي تحتاجها في {$section['name']}؟")
            : self::t($lang,
                "Which equipment would you like for {$section['name']}? I'll then show the tests that run on it. Leave it empty to skip equipment.",
                "ما المعدات التي تريدها في {$section['name']}؟ سأعرض بعدها الفحوص الخاصة بها. اترك القائمة فارغة لتخطي المعدات.");
        $picker = self::base('hardware', $intro.$prompt);
        $picker['labId'] = $lab['labId'];
        $picker['subLabId'] = $section['subLabId'];
        $picker['multi'] = true;
        foreach ($section['hardware'] as $h) {
            $picker['options'][] = [
                'id' => $h['id'],
                'label' => $h['name'],
                'group' => Reconciliation::find($section['categories'], 'id', $h['categoryId'])['name'] ?? null,
                'selected' => in_array($h['id'], $saved['selectedHardwareIds'] ?? [], true),
            ];
        }

        return $picker;
    }

    private static function testsPicker(array $state, array $lab, array $section, string $lang, string $intro = ''): array
    {
        $saved = self::savedSection($state, $lab['labId'], $section['subLabId']);
        $matching = self::testsForHardware($section, ($saved['includeHardware'] ?? false) ? $saved['selectedHardwareIds'] : []);
        $narrowed = count($matching) > 0;
        $hardwareNames = $narrowed ? ($saved['hardwareNames'] ?? []) : [];
        $names = self::list($lang, $hardwareNames);
        $prompt = self::t($lang,
            "Which tests do you need in {$section['name']}?".($narrowed ? " Here are the ones for {$names}." : ''),
            "ما الفحوص التي تحتاجها في {$section['name']}؟".($narrowed ? " هذه الفحوص الخاصة بـ {$names}." : ''));
        $picker = self::base('tests', $intro.$prompt);
        $picker['labId'] = $lab['labId'];
        $picker['subLabId'] = $section['subLabId'];
        $picker['multi'] = true;
        $picker['hardwareNames'] = $hardwareNames;
        foreach ($section['parameters'] as $p) {
            $picker['options'][] = [
                'id' => $p['id'],
                'label' => $p['name'],
                'group' => Reconciliation::find($section['groups'], 'id', $p['groupId'])['name'] ?? null,
                'matchesHardware' => $narrowed ? in_array($p['id'], $matching, true) : true,
                'selected' => in_array($p['id'], $saved['selectedParameterIds'] ?? [], true),
            ];
        }

        return $picker;
    }

    private static function volumePicker(array $state, array $lab, array $section, string $lang): array
    {
        $saved = self::savedSection($state, $lab['labId'], $section['subLabId']);
        $picker = self::base('volume', self::t($lang,
            "About how many tests per year will {$section['name']} run in total?",
            "كم عدد الفحوص المتوقعة سنويًا في {$section['name']} إجمالًا؟"));
        $picker['labId'] = $lab['labId'];
        $picker['subLabId'] = $section['subLabId'];
        $picker['currentVolume'] = $saved !== null && $saved['annualTestVolume'] > 0 ? $saved['annualTestVolume'] : null;

        return $picker;
    }

    /** @param  list<string>  $order */
    private static function donePicker(array $state, array $catalog, string $lang, array $order): array
    {
        $names = [];
        foreach ($order as $id) {
            $name = Reconciliation::find($state['labs'], 'labId', $id)['labName'] ?? null;
            if ($name) {
                $names[] = $name;
            }
        }
        $count = count($names);
        $summary = $count > 1
            ? self::t($lang, "All {$count} laboratories are set up: ".self::list($lang, $names).'.', "تم إعداد جميع المختبرات ({$count}): ".self::list($lang, $names).'.')
            : self::t($lang, 'Your configuration is complete.', 'اكتمل إعدادك.');
        $picker = self::base('done', $summary.' '.self::t($lang, Reconciliation::CONFIRM_EN, Reconciliation::CONFIRM_AR));
        $lastLab = $state['labs'] === [] ? null : $state['labs'][count($state['labs']) - 1];
        $lab = $lastLab === null ? null : self::findLab($catalog, $lastLab['labId']);
        $picker['labId'] = $lab['labId'] ?? null;
        $picker['canAddSection'] = $lab !== null && ! $lab['flat']
            && Reconciliation::any($lab['sections'], fn (array $s) => self::savedSection($state, $lab['labId'], $s['subLabId']) === null);
        $picker['canReview'] = $state['complete'] && count($state['rejected']) === 0;

        return $picker;
    }

    /** The step a saved section still needs, or null when it is complete. */
    private static function sectionStep(array $section, array $saved): ?string
    {
        if ($section['hardwareOnly']) {
            return count($saved['selectedHardwareIds']) === 0 ? 'hardware' : null;
        }

        return match (true) {
            $section['supportsHardware'] && self::untouched($saved) => 'hardware',
            count($saved['selectedParameterIds']) === 0 => 'tests',
            $saved['annualTestVolume'] <= 0 => 'volume',
            default => null,
        };
    }

    /**
     * The next clickable step: an explicit focus, else the first thing still
     * missing, walking the plan one laboratory and one section at a time.
     *
     * @param  array{step: string, labId?: string, subLabId?: ?string}|null  $focus
     * @param  list<string>  $plan
     */
    public static function build(array $state, array $catalog, string $lang, ?array $focus = null, array $plan = []): ?array
    {
        if (count($catalog['labs']) === 0) {
            return null;
        }
        if ($focus !== null && $focus['step'] === 'lab') {
            return self::labPicker($state, $catalog, $lang);
        }
        if ($focus !== null) {
            $lab = self::findLab($catalog, $focus['labId']);
            if ($lab !== null && $focus['step'] === 'section') {
                return self::sectionPicker($state, $lab, $lang, '', false);
            }
            if ($lab !== null) {
                $section = self::findSection($lab, $focus['subLabId']);
                if ($section !== null && self::savedSection($state, $lab['labId'], $section['subLabId']) !== null) {
                    if ($focus['step'] === 'hardware' && $section['supportsHardware']) {
                        return self::hardwarePicker($state, $lab, $section, $lang);
                    }
                    if ($focus['step'] === 'tests' && ! $section['hardwareOnly']) {
                        return self::testsPicker($state, $lab, $section, $lang);
                    }
                    if ($focus['step'] === 'volume' && ! $section['hardwareOnly']) {
                        return self::volumePicker($state, $lab, $section, $lang);
                    }
                }
            }
        }

        // Plan order first, then any laboratory the visitor added by typing.
        $order = array_values(array_filter($plan, fn (mixed $id) => self::findLab($catalog, $id) !== null));
        foreach ($state['labs'] as $l) {
            if (! in_array($l['labId'], $plan, true)) {
                $order[] = $l['labId'];
            }
        }
        $total = count($order);
        if ($total === 0) {
            return self::labPicker($state, $catalog, $lang);
        }

        foreach ($order as $labIndex => $labId) {
            $lab = self::findLab($catalog, $labId);
            $savedLab = Reconciliation::find($state['labs'], 'labId', $labId);
            $previousName = $labIndex > 0 ? (self::findLab($catalog, $order[$labIndex - 1])['labName'] ?? '') : '';
            $position = $labIndex + 1;
            $labIntro = match (true) {
                $total <= 1 => '',
                $labIndex === 0 => self::t($lang, "Let's start with {$lab['labName']} (1 of {$total}). ", "لنبدأ بـ {$lab['labName']} (1 من {$total}). "),
                default => self::t($lang,
                    "{$previousName} is all set. Now let's set up {$lab['labName']} ({$position} of {$total}). ",
                    "تم إعداد {$previousName}. لننتقل الآن إلى {$lab['labName']} ({$position} من {$total}). "),
            };

            if ($savedLab === null) {
                // A planned flat laboratory is saved when chosen; if it was removed by a
                // typed turn there is nothing to ask here.
                if ($lab['flat']) {
                    continue;
                }

                return self::sectionPicker($state, $lab, $lang, $labIntro, true);
            }

            $sections = $savedLab['sections'];
            $sectionCount = count($sections);
            foreach ($sections as $sectionIndex => $saved) {
                $section = self::findSection($lab, $saved['subLabId']);
                if ($section === null) {
                    continue;
                }
                $step = self::sectionStep($section, $saved);
                if ($step === null) {
                    continue;
                }
                $intro = '';
                if (self::untouched($saved)) {
                    $labIsFresh = ! Reconciliation::any($sections, fn (array $s) => ! self::untouched($s));
                    $number = $sectionIndex + 1;
                    $previous = $sections[$sectionIndex - 1]['sectionName'] ?? '';
                    $sectionIntro = match (true) {
                        $sectionCount < 2 || $lab['flat'] => '',
                        $sectionIndex === 0 => self::t($lang, "First up: {$section['name']}. ", "نبدأ بـ {$section['name']}. "),
                        default => self::t($lang,
                            "{$previous} is done. Next up: {$section['name']} ({$number} of {$sectionCount}). ",
                            "اكتمل {$previous}. التالي: {$section['name']} ({$number} من {$sectionCount}). "),
                    };
                    $intro = ($labIsFresh && $lab['flat'] ? $labIntro : '').$sectionIntro;
                }

                return match ($step) {
                    'hardware' => self::hardwarePicker($state, $lab, $section, $lang, $intro),
                    'tests' => self::testsPicker($state, $lab, $section, $lang, $intro),
                    default => self::volumePicker($state, $lab, $section, $lang),
                };
            }
        }

        return self::donePicker($state, $catalog, $lang, $order);
    }

    /**
     * Applies one click to the saved selection. Every id is checked against the
     * published catalog; anything unknown makes the whole click invalid (null).
     *
     * @param  list<string>  $priorPlan
     * @return array{state: array, changed: bool, focus: ?array, plan: list<string>, echo: string}|null
     *                                                                                                  changed is true when the saved selection must be persisted
     */
    public static function apply(array $prior, array $catalog, array $choice, string $lang, array $priorPlan = []): ?array
    {
        $plan = self::sanitizePlan($priorPlan, $catalog);
        $unchanged = fn (?array $focus, string $echo, ?array $nextPlan = null) => [
            'state' => $prior, 'changed' => false, 'focus' => $focus, 'plan' => $nextPlan ?? $plan, 'echo' => $echo,
        ];
        if ($choice['step'] === 'add_lab') {
            return $unchanged(['step' => 'lab'], self::t($lang, 'Add another laboratory', 'إضافة مختبر آخر'));
        }

        $raw = Reconciliation::rawState($prior);
        /** Index path of the section in $raw, created when missing. */
        $ensureSection = function (array $lab, array $section) use (&$raw): array {
            $li = Reconciliation::indexOf($raw['labs'], 'labId', $lab['labId']);
            if ($li === null) {
                $raw['labs'][] = ['labId' => $lab['labId'], 'labType' => $lab['labType'], 'sections' => []];
                $li = count($raw['labs']) - 1;
            }
            $si = Reconciliation::indexOf($raw['labs'][$li]['sections'], 'subLabId', $section['subLabId']);
            if ($si === null) {
                $raw['labs'][$li]['sections'][] = Reconciliation::emptySection($section['subLabId']);
                $si = count($raw['labs'][$li]['sections']) - 1;
            }

            return [$li, $si];
        };
        $commit = function (?array $focus, string $echo, ?array $nextPlan = null) use (&$raw, $catalog, $prior, $plan) {
            $state = SelectionValidation::validate($raw, $catalog);

            return [
                'state' => $state,
                'changed' => Js::stringify(Reconciliation::rawState($state)) !== Js::stringify(Reconciliation::rawState($prior)),
                'focus' => $focus, 'plan' => $nextPlan ?? $plan, 'echo' => $echo,
            ];
        };

        if ($choice['step'] === 'labs' || $choice['step'] === 'lab') {
            $ids = array_values(array_unique($choice['step'] === 'lab' ? [$choice['labId']] : $choice['labIds']));
            $labs = array_map(fn (string $id) => self::findLab($catalog, $id), $ids);
            if ($ids === [] || count($ids) > self::MAX_PLAN_LABS || in_array(null, $labs, true)) {
                return null;
            }
            // Flat laboratories have exactly one section: save it straight away.
            foreach ($labs as $lab) {
                if ($lab['flat']) {
                    $ensureSection($lab, $lab['sections'][0]);
                }
            }
            $nextPlan = array_slice(array_values(array_unique([...$plan, ...$ids])), 0, self::MAX_PLAN_LABS);

            return $commit(null, self::list($lang, array_column($labs, 'labName')), $nextPlan);
        }

        $lab = self::findLab($catalog, $choice['labId']);
        if ($lab === null) {
            return null;
        }
        if ($choice['step'] === 'add_section') {
            return $lab['flat'] ? null : $unchanged(['step' => 'section', 'labId' => $lab['labId']], self::t($lang, 'Add another section', 'إضافة قسم آخر'));
        }
        if ($choice['step'] === 'skip_lab') {
            if (Reconciliation::find($prior['labs'], 'labId', $lab['labId']) !== null) {
                return null;
            }

            return $unchanged(null, self::t($lang, "Skip {$lab['labName']}", "تخطي {$lab['labName']}"),
                array_values(array_filter($plan, fn (string $id) => $id !== $lab['labId'])));
        }

        if ($choice['step'] === 'sections' || $choice['step'] === 'section') {
            $subIds = [];
            foreach ($choice['step'] === 'section' ? [$choice['subLabId']] : $choice['subLabIds'] as $subId) {
                if (! in_array($subId, $subIds, true)) {
                    $subIds[] = $subId;
                }
            }
            $sections = array_map(fn (?string $id) => self::findSection($lab, $id), $subIds);
            if ($subIds === [] || in_array(null, $sections, true)) {
                return null;
            }
            foreach ($sections as $section) {
                $ensureSection($lab, $section);
            }
            $nextPlan = in_array($lab['labId'], $plan, true) ? $plan : array_slice([...$plan, $lab['labId']], 0, self::MAX_PLAN_LABS);

            return $commit(null, "{$lab['labName']} › ".self::list($lang, array_column($sections, 'name')), $nextPlan);
        }

        $section = self::findSection($lab, $choice['subLabId']);
        if ($section === null || self::savedSection($prior, $lab['labId'], $section['subLabId']) === null) {
            return null;
        }

        if ($choice['step'] === 'hardware') {
            if (! $section['supportsHardware']) {
                return null;
            }
            $ids = array_values(array_unique($choice['ids']));
            if (array_diff($ids, array_column($section['hardware'], 'id')) !== []) {
                return null;
            }
            [$li, $si] = $ensureSection($lab, $section);
            $raw['labs'][$li]['sections'][$si]['selectedHardwareIds'] = $ids;
            $raw['labs'][$li]['sections'][$si]['includeHardware'] = count($ids) > 0;
            $names = array_column(array_filter($section['hardware'], fn (array $h) => in_array($h['id'], $ids, true)), 'name');

            return $commit(
                $section['hardwareOnly'] ? null : ['step' => 'tests', 'labId' => $lab['labId'], 'subLabId' => $section['subLabId']],
                $names !== [] ? self::list($lang, $names) : self::t($lang, 'No equipment', 'بدون معدات'),
            );
        }
        if ($choice['step'] === 'tests') {
            if ($section['hardwareOnly']) {
                return null;
            }
            $ids = array_values(array_unique($choice['ids']));
            if (array_diff($ids, array_column($section['parameters'], 'id')) !== []) {
                return null;
            }
            [$li, $si] = $ensureSection($lab, $section);
            $raw['labs'][$li]['sections'][$si]['selectedParameterIds'] = $ids;
            $names = array_column(array_filter($section['parameters'], fn (array $p) => in_array($p['id'], $ids, true)), 'name');
            $count = count($names);
            $echo = match (true) {
                $count === 0 => self::t($lang, 'No tests', 'بدون فحوص'),
                $count <= 3 => self::list($lang, $names),
                default => self::t($lang, "{$count} tests", "{$count} فحص"),
            };

            return $commit(
                $raw['labs'][$li]['sections'][$si]['annualTestVolume'] > 0 || count($ids) === 0
                    ? null : ['step' => 'volume', 'labId' => $lab['labId'], 'subLabId' => $section['subLabId']],
                $echo,
            );
        }
        // volume
        if ($section['hardwareOnly']) {
            return null;
        }
        $volume = Js::round(Js::toNumber($choice['volume']));
        if (! is_finite($volume) || $volume <= 0 || $volume > self::MAX_VOLUME) {
            return null;
        }
        [$li, $si] = $ensureSection($lab, $section);
        $raw['labs'][$li]['sections'][$si]['annualTestVolume'] = $volume;
        $grouped = Js::grouped($volume);

        return $commit(null, self::t($lang, "{$grouped} tests per year", "{$grouped} فحص سنويًا"));
    }

    /** Shape check for an untrusted choice from the browser (ids are bounded strings). */
    public static function parseChoice(mixed $input): ?array
    {
        if (! is_array($input) || $input === []) {
            return null;
        }
        $id = fn (mixed $v) => is_string($v) && $v !== '' && Js::length($v) <= 64 ? $v : null;
        $ids = fn (mixed $v, int $max) => is_array($v) && array_is_list($v) && count($v) <= $max
            && ! Reconciliation::any($v, fn (mixed $x) => $id($x) === null) ? $v : null;
        $labId = $id($input['labId'] ?? null);
        $hasSub = array_key_exists('subLabId', $input);
        $subLabId = $hasSub ? $input['subLabId'] : null;
        $subOk = $hasSub && ($subLabId === null || $id($subLabId) !== null);
        $step = $input['step'] ?? null;
        switch ($step) {
            case 'add_lab':
                return ['step' => 'add_lab'];
            case 'lab':
            case 'add_section':
            case 'skip_lab':
                return $labId !== null ? ['step' => $step, 'labId' => $labId] : null;
            case 'labs':
                $labIds = $ids($input['labIds'] ?? null, self::MAX_PLAN_LABS);

                return $labIds !== null && count($labIds) > 0 ? ['step' => 'labs', 'labIds' => $labIds] : null;
            case 'section':
                return $labId !== null && $subOk ? ['step' => 'section', 'labId' => $labId, 'subLabId' => $subLabId] : null;
            case 'sections':
                $raw = $input['subLabIds'] ?? null;
                $ok = is_array($raw) && array_is_list($raw) && count($raw) > 0 && count($raw) <= 100
                    && ! Reconciliation::any($raw, fn (mixed $x) => $x !== null && $id($x) === null);

                return $labId !== null && $ok ? ['step' => 'sections', 'labId' => $labId, 'subLabIds' => $raw] : null;
            case 'hardware':
            case 'tests':
                $list = $ids($input['ids'] ?? null, $step === 'hardware' ? 100 : 300);

                return $labId !== null && $subOk && $list !== null ? ['step' => $step, 'labId' => $labId, 'subLabId' => $subLabId, 'ids' => $list] : null;
            case 'volume':
                $volume = $input['volume'] ?? null;

                return $labId !== null && $subOk && (is_int($volume) || is_float($volume))
                    ? ['step' => 'volume', 'labId' => $labId, 'subLabId' => $subLabId, 'volume' => $volume] : null;
            default:
                return null;
        }
    }
}
