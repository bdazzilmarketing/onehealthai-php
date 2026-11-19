<?php

namespace App\Ai;

use App\Support\JsRegex;

/** Port of src/lib/ai-language.ts. */
final class Language
{
    /**
     * Reply language for one visitor turn. Order: explicit request; otherwise the
     * visitor's own words with full catalog names removed (a long English product
     * name never overrides an Arabic command); a turn with no words of its own
     * (a number, a bare item name) keeps the language of the last accepted reply.
     *
     * @param  list<string>  $catalogNames
     */
    public static function conversationLanguage(string $message, string $previousReply, array $catalogNames): string
    {
        if (JsRegex::test('\b(?:in english|english please|reply in english)\b|بالانجليزي|بالإنجليزي', 'i', $message)) {
            return 'en';
        }
        if (JsRegex::test('\bin arabic\b|بالعربي', 'i', $message)) {
            return 'ar';
        }
        $own = $message;
        foreach (CatalogMatching::exactlyNamedItems($message, $catalogNames) as $name) {
            $own = JsRegex::replace(JsRegex::escape($name), 'i', $own, ' ');
        }
        $ar = JsRegex::count('[؀-ۿ]', '', $own);
        $en = JsRegex::count('[A-Za-z]', '', $own);
        if ($ar === 0 && $en === 0) {
            return JsRegex::count('[؀-ۿ]', '', $previousReply) > 0 ? 'ar' : Guard::replyLanguageFor($message);
        }
        if ($ar > 0 && $ar >= $en) {
            return 'ar';
        }

        return Guard::replyLanguageFor($own);
    }
}
