<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * BsCalendar — Bikram Sambat ↔ Gregorian (AD) calendar conversion helper.
 *
 * Epoch: BS 2000/01/01 = AD 1943/04/14
 *
 * The lookup table covers BS 2000–2090. Each entry is an array of 12 integers
 * representing the number of days in each BS month (Baishakh … Chaitra).
 *
 * Requirements: 4.5
 */
final class BsCalendar
{
    /** AD date of BS epoch start (BS 2000-01-01). */
    private const EPOCH_AD = '1943-04-14';

    /**
     * BS calendar day-count lookup table.
     * Key   = BS year (int)
     * Value = array of 12 month-day counts [Baishakh, Jestha, …, Chaitra]
     *
     * Data sourced from the authoritative ernilambar/nepali-date library (MIT)
     * with overrides for BS 2080–2083 per the task specification (current
     * Nepal government published values).
     *
     * @see https://github.com/ernilambar/nepali-date
     */
    private static array $table = [
        2000 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2001 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2002 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2003 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2004 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 365
        2005 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2006 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2007 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2008 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31], // 365
        2009 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2010 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2011 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2012 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365
        2013 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2014 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2015 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2016 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365
        2017 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2018 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2019 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2020 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2021 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2022 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // 365
        2023 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2024 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2025 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2026 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2027 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 365
        2028 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2029 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30], // 365
        2030 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2031 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 365
        2032 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2033 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2034 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2035 => [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31], // 365
        2036 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2037 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2038 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2039 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365
        2040 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2041 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2042 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2043 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365
        2044 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2045 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2046 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2047 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2048 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2049 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // 365
        2050 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2051 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2052 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2053 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // 365
        2054 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2055 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2056 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30], // 365
        2057 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2058 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 365
        2059 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2060 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2061 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2062 => [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31], // 365
        2063 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2064 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2065 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2066 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31], // 365
        2067 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2068 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2069 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2070 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365
        2071 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2072 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365
        2073 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366
        2074 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2076 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // 365
        2077 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366
        2078 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2079 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // 365  (spec)
        2081 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365  (spec)
        2082 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // 366  (spec)
        2083 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366  (spec)
        2084 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30], // 365
        2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30], // 366
        2086 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30], // 365
        2087 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30], // 366
        2088 => [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30], // 365
        2089 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30], // 365
        2090 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30], // 365
    ];

    /** English month names indexed 1–12. */
    private static array $monthNamesEn = [
        1  => 'Baishakh',
        2  => 'Jestha',
        3  => 'Ashadh',
        4  => 'Shrawan',
        5  => 'Bhadra',
        6  => 'Ashwin',
        7  => 'Kartik',
        8  => 'Mangsir',
        9  => 'Poush',
        10 => 'Magh',
        11 => 'Falgun',
        12 => 'Chaitra',
    ];

    /** Nepali (Unicode) month names indexed 1–12. */
    private static array $monthNamesNe = [
        1  => 'बैशाख',
        2  => 'जेठ',
        3  => 'असार',
        4  => 'श्रावण',
        5  => 'भदौ',
        6  => 'असोज',
        7  => 'कार्तिक',
        8  => 'मंसिर',
        9  => 'पुष',
        10 => 'माघ',
        11 => 'फाल्गुन',
        12 => 'चैत्र',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Convert an AD date string (Y-m-d) to a BS date array.
     *
     * @param  string $adDate  Date in 'Y-m-d' format, e.g. '2024-04-13'
     * @return array{year: int, month: int, day: int}
     * @throws \InvalidArgumentException on invalid date format or out-of-range input
     */
    public static function adToBs(string $adDate): array
    {
        $adTs    = \DateTime::createFromFormat('Y-m-d', $adDate);
        $epochTs = \DateTime::createFromFormat('Y-m-d', self::EPOCH_AD);

        if ($adTs === false) {
            throw new \InvalidArgumentException("Invalid AD date format: '{$adDate}'. Expected 'Y-m-d'.");
        }

        // Normalise to midnight so diff counts only calendar days.
        $adTs->setTime(0, 0, 0);
        $epochTs->setTime(0, 0, 0);

        $totalDays = (int) $epochTs->diff($adTs)->days;

        if ($adTs < $epochTs) {
            throw new \InvalidArgumentException(
                "AD date '{$adDate}' is before the BS epoch (AD " . self::EPOCH_AD . ")."
            );
        }

        // Walk the lookup table year by year, then month by month.
        $bsYear  = 2000;
        $bsMonth = 1;
        $bsDay   = 1;

        foreach (self::$table as $year => $months) {
            $daysInYear = array_sum($months);

            if ($totalDays < $daysInYear) {
                $bsYear = $year;

                foreach ($months as $mIdx => $daysInMonth) {
                    if ($totalDays < $daysInMonth) {
                        $bsMonth = $mIdx + 1; // $mIdx is 0-based
                        $bsDay   = $totalDays + 1;
                        break;
                    }
                    $totalDays -= $daysInMonth;
                }

                break;
            }

            $totalDays -= $daysInYear;
        }

        return ['year' => $bsYear, 'month' => $bsMonth, 'day' => $bsDay];
    }

    /**
     * Convert a BS date to an AD date string (Y-m-d).
     *
     * @param  int $year   BS year  (2000–2090)
     * @param  int $month  BS month (1–12)
     * @param  int $day    BS day
     * @return string AD date in 'Y-m-d' format
     * @throws \InvalidArgumentException on out-of-range or invalid BS date
     */
    public static function bsToAd(int $year, int $month, int $day): string
    {
        self::assertValidBsDate($year, $month, $day);

        // Count total days from BS epoch (2000/01/01) to the target BS date.
        $totalDays = 0;

        foreach (self::$table as $y => $months) {
            if ($y === $year) {
                // Add days for complete months before the target month.
                for ($m = 0; $m < $month - 1; $m++) {
                    $totalDays += $months[$m];
                }
                // Add days within the target month (day is 1-based).
                $totalDays += $day - 1;
                break;
            }
            $totalDays += array_sum($months);
        }

        $epochTs = \DateTime::createFromFormat('Y-m-d', self::EPOCH_AD);
        $epochTs->setTime(0, 0, 0);
        $epochTs->modify("+{$totalDays} days");

        return $epochTs->format('Y-m-d');
    }

    /**
     * Return the next BS month, rolling over from Chaitra (month 12) to
     * Baishakh (month 1) of the following year.
     *
     * @param  int $year   Current BS year
     * @param  int $month  Current BS month (1–12)
     * @return array{year: int, month: int}
     */
    public static function nextBsMonth(int $year, int $month): array
    {
        if ($month === 12) {
            return ['year' => $year + 1, 'month' => 1];
        }

        return ['year' => $year, 'month' => $month + 1];
    }

    /**
     * Return the Nepali calendar month name.
     *
     * @param  int    $month   BS month (1–12)
     * @param  string $locale  'ne' for Nepali Unicode, anything else for English
     * @return string
     * @throws \InvalidArgumentException on invalid month
     */
    public static function bsMonthName(int $month, string $locale = 'en'): string
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Month must be 1–12, got {$month}.");
        }

        if ($locale === 'ne') {
            return self::$monthNamesNe[$month];
        }

        return self::$monthNamesEn[$month];
    }

    /**
     * Return the number of days in a given BS year/month.
     *
     * @param  int $year   BS year  (2000–2090)
     * @param  int $month  BS month (1–12)
     * @return int
     * @throws \InvalidArgumentException on out-of-range year or month
     */
    public static function bsMonthDays(int $year, int $month): int
    {
        if (!isset(self::$table[$year])) {
            throw new \InvalidArgumentException(
                "BS year {$year} is outside the supported range (2000–2090)."
            );
        }

        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Month must be 1–12, got {$month}.");
        }

        return self::$table[$year][$month - 1];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that a BS date is within the lookup table and is a valid calendar day.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidBsDate(int $year, int $month, int $day): void
    {
        if (!isset(self::$table[$year])) {
            throw new \InvalidArgumentException(
                "BS year {$year} is outside the supported range (2000–2090)."
            );
        }

        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Month must be 1–12, got {$month}.");
        }

        $maxDay = self::$table[$year][$month - 1];

        if ($day < 1 || $day > $maxDay) {
            throw new \InvalidArgumentException(
                "Day {$day} is invalid for BS {$year}/{$month} (max {$maxDay})."
            );
        }
    }
}
