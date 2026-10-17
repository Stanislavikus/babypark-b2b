<?php

namespace App\Support\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;

final class OAuth1FormUrlEncodedParser
{
    /**
     * @return list<OAuth1ParameterPair>
     */
    public function parse(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $pairs = [];

        foreach (explode('&', $input) as $pair) {
            $equalsPosition = strpos($pair, '=');

            if ($equalsPosition === false) {
                $pairs[] = new OAuth1ParameterPair($this->decodeComponent($pair), '');
            } else {
                $pairs[] = new OAuth1ParameterPair(
                    $this->decodeComponent(substr($pair, 0, $equalsPosition)),
                    $this->decodeComponent(substr($pair, $equalsPosition + 1)),
                );
            }
        }

        return $pairs;
    }

    private function decodeComponent(string $value): string
    {
        $length = strlen($value);
        $decoded = '';

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($character === '+') {
                $decoded .= ' ';

                continue;
            }

            if ($character !== '%') {
                $decoded .= $character;

                continue;
            }

            if ($index + 2 >= $length) {
                throw new OAuth1StructuralException('Malformed percent-escape in form or query parameter.');
            }

            $hexDigits = substr($value, $index + 1, 2);

            if (! ctype_xdigit($hexDigits)) {
                throw new OAuth1StructuralException('Malformed percent-escape in form or query parameter.');
            }

            $decoded .= chr(hexdec($hexDigits));
            $index += 2;
        }

        return $decoded;
    }
}
