<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/** One normalized bilingual parser shared by reconciliation and confirmation (port of src/lib/ai-action-parser.ts). */
final class ActionParser
{
    private const ACTION = '\b(add|select|include|configure|remove|delete|exclude|replace|change|keep)\b|(?<![؀-ۿ])(?:اضافه|اضافة|اضف(?:ها|هم)?|اختر|ادرج|احذف|ازل|استبعد|استبدل|غير|احتفظ|اجعل|ابق)(?![؀-ۿ])';

    private const HARDWARE_TARGET = '(?:(?:the|any|all|selected)\s+)*(?:hardware|equipment|analyzers?)\b|(?:كل\s*)?(?:ال)?(?:اجهزه|اجهزة|معدات)';

    private static function actionKind(string $word): string
    {
        return match (true) {
            JsRegex::test('^(remove|delete|exclude|احذف|ازل|استبعد)$', '', $word) => 'remove',
            JsRegex::test('^(replace|استبدل)$', '', $word) => 'replace',
            JsRegex::test('^(change|غير|اجعل)$', '', $word) => 'change',
            JsRegex::test('^(keep|احتفظ|ابق)$', '', $word) => 'keep',
            default => 'add',
        };
    }

    /**
     * @return array{text: string, actions: list<array{kind: string, target: string}>, hardwareOff: bool, negated: bool, confirmation: bool, explicitSelection: bool, discovery: bool}
     */
    public static function parse(string $message): array
    {
        $text = CatalogMatching::normalizeLabText($message);
        $target = self::HARDWARE_TARGET;
        $hardwareOff = JsRegex::test('\b(?:no|without|remove|delete|exclude)\s+(?:'.$target.')|(?:بدون|احذف|ازل|استبعد)\s*(?:'.$target.')|لا\s*(?:اريد|نحتاج)?\s*(?:ال)?(?:اجهز[هة]|معدات)', '', $text);
        $negated = JsRegex::test('\b(?:do not|don\'t|never)\s+(?:remove|delete|exclude|replace|add|include)|لا\s*(?:تحذف|تزل|تستبعد|تستبدل|تضف)', '', $text);
        $matches = JsRegex::matchAll(self::ACTION, '', $text);
        $actions = [];
        if (! $negated) {
            foreach ($matches as $index => $match) {
                $start = $match['index'] + strlen($match[0]);
                $end = $matches[$index + 1]['index'] ?? strlen($text);
                $action = ['kind' => self::actionKind($match[0]), 'target' => Js::trim(substr($text, $start, $end - $start))];
                if (! ($action['kind'] === 'remove' && JsRegex::test('^(?:'.$target.')', '', $action['target']))) {
                    $actions[] = $action;
                }
            }
        }
        $confirmation = JsRegex::test('^(yes|yes please|correct|continue|looks good|that\'s everything|نعم|صحيح|تابع|اكمل|استمر|موافق)[.!؟\s]*$', '', Js::trim($text));
        $explicitSelection = false;
        foreach ($actions as $action) {
            $explicitSelection = $explicitSelection || in_array($action['kind'], ['add', 'replace', 'change', 'keep'], true);
        }
        $discovery = ! $explicitSelection && JsRegex::test('\b(describe|which|what|available|list|explain|show|tell)\b|صف لي|اوصف|ما هي|ماهي|المتاح|اشرح|اعرض', '', $text);

        return ['text' => $text, 'actions' => $actions, 'hardwareOff' => $hardwareOff, 'negated' => $negated,
            'confirmation' => $confirmation, 'explicitSelection' => $explicitSelection, 'discovery' => $discovery];
    }
}
