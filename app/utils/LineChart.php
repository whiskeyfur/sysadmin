<?php

namespace App\Utils;

/**
 * A small line chart rendered as inline SVG on the server: no chart library
 * or CDN, works offline and follows the page's light/dark colours (axes use
 * currentColor). Series are lists of [unix time, value].
 */
class LineChart
{
    private const WIDTH = 900;

    private const HEIGHT = 260;

    private const LEFT = 48;

    private const RIGHT = 16;

    private const TOP = 14;

    private const BOTTOM = 30;

    /**
     * Distinguishable in light and dark themes.
     */
    private const COLOURS = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

    /**
     * @param array<string, list<array{0: int, 1: float}>> $series name => points
     * @param float|null $max a fixed top of the scale (e.g. 100 for percentages); null to fit the data
     */
    public static function render(string $title, array $series, int $from, int $to, string $unit = '', ?float $max = null): string
    {
        $values = array_merge([], ...array_map(fn (array $points) => array_column($points, 1), array_values($series)));

        if ($values === []) {
            return '<p class="muted">No data in this period.</p>';
        }

        $top = $max ?? self::niceCeiling(max($values) * 1.1);
        $top = $top > 0 ? $top : 1;
        $span = max(1, $to - $from);
        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $x = fn (int $time) => self::LEFT + ($time - $from) / $span * $plotWidth;
        $y = fn (float $value) => self::TOP + (1 - min($value, $top) / $top) * $plotHeight;
        $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES);

        $svg = [sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s">',
            self::WIDTH,
            self::HEIGHT,
            $e($title . ' over time'),
        )];

        // Horizontal grid lines with their values.
        for ($i = 0; $i <= 4; $i++) {
            $value = $top * $i / 4;
            $lineY = round($y($value), 1);
            $svg[] = sprintf('<line x1="%d" x2="%d" y1="%s" y2="%s" class="grid"/>', self::LEFT, self::WIDTH - self::RIGHT, $lineY, $lineY);
            $svg[] = sprintf('<text x="%d" y="%s" class="axis" text-anchor="end" dominant-baseline="middle">%s</text>', self::LEFT - 6, $lineY, $e(self::number($value) . $unit));
        }

        // Time labels: start, middle, end.
        foreach ([[$from, 'start'], [intdiv($from + $to, 2), 'middle'], [$to, 'end']] as [$time, $anchor]) {
            $svg[] = sprintf(
                '<text x="%s" y="%d" class="axis" text-anchor="%s">%s</text>',
                round($x($time), 1),
                self::HEIGHT - 8,
                $anchor,
                $e(LocalTime::format((new \DateTimeImmutable())->setTimestamp($time), 'M j H:i')),
            );
        }

        $index = 0;

        foreach ($series as $name => $points) {
            $colour = self::COLOURS[$index++ % count(self::COLOURS)];
            $coordinates = array_map(fn (array $point) => round($x($point[0]), 1) . ',' . round($y($point[1]), 1), $points);

            if (count($points) > 1) {
                $svg[] = sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2" vector-effect="non-scaling-stroke"/>', implode(' ', $coordinates), $colour);
            }

            foreach ($points as $i => $point) {
                [$cx, $cy] = explode(',', $coordinates[$i]);
                $svg[] = sprintf(
                    '<circle cx="%s" cy="%s" r="3" fill="%s"><title>%s</title></circle>',
                    $cx,
                    $cy,
                    $colour,
                    $e($name . ': ' . self::number($point[1]) . $unit . ' at ' . LocalTime::format((new \DateTimeImmutable())->setTimestamp($point[0]))),
                );
            }
        }

        $svg[] = '</svg>';

        $legend = [];
        $index = 0;

        foreach (array_keys($series) as $name) {
            $legend[] = sprintf('<span><i style="background:%s"></i>%s</span>', self::COLOURS[$index++ % count(self::COLOURS)], $e((string) $name));
        }

        return '<figure class="chart"><figcaption>' . $e($title) . '</figcaption>' . implode('', $svg)
            . '<div class="legend">' . implode('', $legend) . '</div></figure>';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Round a scale's top up to 1, 2, 2.5 or 5 times a power of ten.
     */
    private static function niceCeiling(float $value): float
    {
        if ($value <= 0) {
            return 1;
        }

        $power = 10 ** floor(log10($value));

        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($value <= $step * $power) {
                return $step * $power;
            }
        }

        return 10 * $power;
    }
}
