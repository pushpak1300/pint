<?php

namespace App\Mago;

use PhpToken;

final class Layout
{
    /**
     * Finish Laravel layouts that Mago cannot configure independently.
     */
    public static function format(string $code, bool $anonymousBraces): string
    {
        $tokens = PhpToken::tokenize($code);
        $newline = "\n";

        // String contents may use different line endings than the PHP around them.
        foreach ($tokens as $token) {
            if ($token->is([T_OPEN_TAG, T_WHITESPACE]) && preg_match('/\r?\n/', $token->text, $ending)) {
                $newline = $ending[0];

                break;
            }
        }

        $depth = 0;
        $classes = [];
        $previous = 0;

        foreach ($tokens as $index => $token) {
            if ($token->id === T_COMMENT && str_starts_with($token->text, '/*')) {
                $line = substr($code, 0, $token->pos);
                $indent = substr($line, strrpos("\n".$line, "\n"));

                if (trim($indent) === '' && preg_match('/\n[ \t]*\|/', $token->text)) {
                    $token->text = preg_replace('/\r?\n[ \t]+(?=\||\*\/)/', $newline.$indent, $token->text);
                }
            }

            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->id === T_CLASS && $tokens[$previous]->id !== T_DOUBLE_COLON) {
                $classes[$depth] = $index;
            } elseif ($token->id === T_STRING && $tokens[$previous]->id === T_CLASS) {
                unset($classes[$depth]);
            }

            if (in_array(
                $token->id,
                [ord('('), ord('['), ord('{'), T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES],
                true,
            )) {
                if ($token->id === ord('{') && isset($classes[$depth])) {
                    $class = $tokens[$classes[$depth]];
                    $signature = substr($code, $class->pos, $token->pos - $class->pos);

                    if (
                        $anonymousBraces
                        && ! str_contains($signature, "\n")
                        && $tokens[$index - 1]->id === T_WHITESPACE
                    ) {
                        $line = substr($code, 0, $class->pos);
                        preg_match('/^[ \t]*/', substr($line, strrpos("\n".$line, "\n")), $indent);
                        $tokens[$index - 1]->text = $newline.$indent[0];
                    }

                    unset($classes[$depth]);
                }

                $depth++;
            } elseif (in_array($token->id, [ord(')'), ord(']'), ord('}')], true)) {
                $depth--;
            }

            $previous = $index;
        }

        return implode('', array_map(fn (PhpToken $token) => $token->text, $tokens));
    }
}
