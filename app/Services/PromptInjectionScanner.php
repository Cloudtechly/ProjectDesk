<?php

namespace App\Services;

class PromptInjectionScanner
{
    /**
     * @param  list<array{locator_type: string, locator: string, text: string}>  $segments
     * @return array{risk: string, matches: list<array{severity: string, signature: string}>}
     */
    public function scan(array $segments): array
    {
        $text = mb_strtolower(implode("\n", array_column($segments, 'text')));
        $critical = [
            '/ignore (all|any|the|previous|prior) (instructions|rules)/iu',
            '/(system|developer)\s*(prompt|message)/iu',
            '/(call|use|invoke)\s+(a |the )?(tool|function|database|shell)/iu',
            '/تجاهل\s+(كل|أي|التعليمات|الأوامر)/u',
            '/(نفذ|استدع)\s+(الأداة|أداة|قاعدة البيانات|الأوامر)/u',
        ];
        $high = [
            '/you are now/iu', '/developer mode/iu', '/\[inst\]|<system>|###\s*system/iu',
            '/اعرض\s+(تعليماتك|الأوامر السرية|رسالة النظام)/u',
        ];
        $matches = [];
        foreach ($critical as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $matches[] = ['severity' => 'critical', 'signature' => $pattern];
            }
        }
        foreach ($high as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $matches[] = ['severity' => 'high', 'signature' => $pattern];
            }
        }
        $risk = collect($matches)->contains('severity', 'critical') ? 'critical'
            : (collect($matches)->contains('severity', 'high') ? 'high' : 'none');

        return ['risk' => $risk, 'matches' => $matches];
    }
}
