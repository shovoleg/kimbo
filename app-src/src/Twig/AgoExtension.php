<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AgoExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', [$this, 'ago']),
        ];
    }

    public function ago(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return 'never';
        }

        $seconds = time() - $date->getTimestamp();

        if ($seconds < 0) {
            $seconds = 0;
        }

        if ($seconds < 60) {
            return 'less than a minute ago';
        }

        $units = [
            ['seconds' => 31536000, 'name' => 'year'],
            ['seconds' => 2592000,  'name' => 'month'],
            ['seconds' => 604800,   'name' => 'week'],
            ['seconds' => 86400,    'name' => 'day'],
            ['seconds' => 3600,     'name' => 'hour'],
            ['seconds' => 60,       'name' => 'minute'],
        ];

        foreach ($units as $unit) {
            if ($seconds >= $unit['seconds']) {
                $value = (int) floor($seconds / $unit['seconds']);

                return \sprintf('%d %s%s ago', $value, $unit['name'], 1 === $value ? '' : 's');
            }
        }

        return 'less than a minute ago';
    }
}
