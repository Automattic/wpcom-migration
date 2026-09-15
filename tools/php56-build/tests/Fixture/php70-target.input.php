<?php

final class Php70TargetFixture
{
    private const LIMIT = 3;

    public function limit(?array $options): int
    {
        $limit = $this->lookup($options) ?? self::LIMIT;
        [$first] = [$limit];

        return $first <=> 2;
    }

    private function lookup(?array $options): ?int
    {
        return $options['limit'] ?? null;
    }
}

echo (new Php70TargetFixture())->limit(['limit' => 5]), "\n";
echo (new Php70TargetFixture())->limit(null), "\n";
