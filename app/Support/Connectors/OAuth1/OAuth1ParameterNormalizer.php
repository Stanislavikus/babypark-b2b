<?php

namespace App\Support\Connectors\OAuth1;

final class OAuth1ParameterNormalizer
{
    /**
     * @param  list<OAuth1ParameterPair>  $pairs
     */
    public function normalize(array $pairs): string
    {
        $encodedPairs = [];

        foreach ($pairs as $pair) {
            $encodedPairs[] = [
                OAuth1PercentEncoder::encode($pair->name),
                OAuth1PercentEncoder::encode($pair->value),
            ];
        }

        usort(
            $encodedPairs,
            static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]),
        );

        $normalized = [];

        foreach ($encodedPairs as [$name, $value]) {
            $normalized[] = $name.'='.$value;
        }

        return implode('&', $normalized);
    }
}
