<?php

namespace App;

enum BudgetType: string
{
    case ANNUAL = 'annual';
    case SUPPLEMENTAL = 'supplemental';

    public function label(): string
    {
        return match($this) {
            self::ANNUAL => 'Annual Budget',
            self::SUPPLEMENTAL => 'Supplemental Budget',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::ANNUAL => 'The main annual budget for the fiscal year',
            self::SUPPLEMENTAL => 'Additional budget allocation during the fiscal year',
        };
    }

    public function isAnnual(): bool
    {
        return $this === self::ANNUAL;
    }

    public function isSupplemental(): bool
    {
        return $this === self::SUPPLEMENTAL;
    }

    public static function fromDescription(string $description): self
    {
        $description = strtolower($description);
        
        if (str_contains($description, 'supplemental')) {
            return self::SUPPLEMENTAL;
        }
        
        return self::ANNUAL;
    }
}