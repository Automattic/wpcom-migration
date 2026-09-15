<?php

final class Php70TargetFixture
{
    const LIMIT = 3;

    public function limit($options)
    {
        $limit = $this->lookup($options) ?? self::LIMIT;
        list($first) = [$limit];

        return $first <=> 2;
    }

    private function lookup($options)
    {
        return $options['limit'] ?? null;
    }
}

echo (new Php70TargetFixture())->limit(['limit' => 5]), "\n";
echo (new Php70TargetFixture())->limit(null), "\n";
