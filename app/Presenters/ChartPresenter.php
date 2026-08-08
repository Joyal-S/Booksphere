<?php

declare(strict_types=1);

namespace BookSphere\App\Presenters;

/**
 * ChartPresenter
 *
 * The presentation helpers of the CHARTS & REPORTS layer
 * (Phase 12.5). Its ONLY job is to shape ALREADY-COMPUTED analytics
 * arrays (the UserAnalytics / BookAnalytics / recommendation payloads
 * the services build) into the JSON config the chart-card component
 * and public/assets/js/charts.js render. It never calculates a
 * statistic - a chart's numbers are always the exact numbers the
 * analytics services produced.
 *
 * Chart registry (the only types the app renders):
 *
 *     'doughnut' - one series; proportions of a whole
 *                  (shelf split, rating distribution, signals)
 *     'line'     - one or two series over a time axis
 *                  (monthly activity)
 *     'bar'      - one or two series over a label axis
 *                  (rating buckets, page ranges)
 *     'hbar'     - horizontal bars (genres, authors, languages)
 *
 * Tones: datasets carry a design TOKEN name ('primary', 'info',
 * 'success', 'warning', 'danger', 'secondary') instead of a hex
 * color. charts.js resolves the token to the active theme's color at
 * render time, so a chart always follows BookSphere's light and dark
 * palettes without a single hard-coded color in the payload.
 */
final class ChartPresenter
{
    /**
     * The doughnut shape: labels against values, one dataset.
     *
     * @param array<int, string> $labels
     * @param array<int, int>    $values
     * @return string the HTML-safe JSON config for the chart-card
     */
    public static function doughnut(string $key, array $labels, array $values, string $summary): string
    {
        return self::json([
            'type'    => 'doughnut',
            'key'     => $key,
            'labels'  => array_values($labels),
            'sets'    => [['label' => $summary, 'tone' => 'primary', 'values' => array_values($values)]],
            'summary' => $summary,
        ]);
    }

    /**
     * The vertical-bar shape with optional overlaid series.
     *
     * @param array<int, string> $labels
     * @param array<int, array{label: string, tone: string, values: array<int, float>}> $sets
     * @return string  the HTML-safe JSON config for the chart-card
     */
    public static function bar(string $key, array $labels, array $sets, string $summary): string
    {
        return self::json([
            'type'    => 'bar',
            'key'     => $key,
            'labels'  => array_values($labels),
            'sets'    => $sets,
            'summary' => $summary,
        ]);
    }

    /**
     * The horizontal-bar shape.
     *
     * @param array<int, string> $labels
     * @param array<int, float>  $values
     * @return string  the HTML-safe JSON config for the chart-card
     */
    public static function hbar(string $key, array $labels, array $values, string $summary, string $tone = 'info'): string
    {
        return self::json([
            'type'    => 'hbar',
            'key'     => $key,
            'labels'  => array_values($labels),
            'sets'    => [['label' => $summary, 'tone' => $tone, 'values' => array_values($values)]],
            'summary' => $summary,
        ]);
    }

    /**
     * The line/area shape over a time axis.
     *
     * @param array<int, string> $labels
     * @param array<int, array{label: string, tone: string, values: array<int, float>}> $sets
     * @return string  the HTML-safe JSON config for the chart-card
     */
    public static function line(string $key, array $labels, array $sets, string $summary): string
    {
        return self::json([
            'type'    => 'line',
            'key'     => $key,
            'labels'  => array_values($labels),
            'sets'  => $sets,
            'summary' => $summary,
        ]);
    }

    /**
     * JSON-escape a chart config for the view (HTML-safe).
     *
     * @param array<string, mixed> $config
     */
    public static function json(array $config): string
    {
        return json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}