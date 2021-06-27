<?php

namespace App\Helpers;

class FeedHelper
{
    /**
     * Clears empty elements from the array of product features
     * @param array $short_desc Product Features
     * @return array Enhanced Features
     */
    public static function normalizeShortDesc( array $short_desc ): array
    {
        $short_desc = array_map( static fn( $desc ) => StringHelper::normalizeSpaceInString( $desc ), $short_desc );
        return array_filter( $short_desc, static fn( $desc ) => str_replace( ' ', '', $desc ) );
    }

    /**
     * Gets the dimensions of the product from the line
     * @param string $string A string containing the dimensions
     * @param string $separator Separator, which is used to convert a string into an array with the dimensions of the product
     * @param int $x_index Size index in the array on the X axis
     * @param int $y_index Size index in the array on the Y axis
     * @param int $z_index Size index in the array on the Z axis
     * @return array Array containing the dimensions of the product
     */
    public static function getDimsInString( string $string, string $separator, int $x_index = 0, int $y_index = 1, int $z_index = 2 ): array
    {
        $raw_dims = explode( $separator, $string );

        $dims[ 'x' ] = isset( $raw_dims[ $x_index ] ) ? StringHelper::getFloat( $raw_dims[ $x_index ] ) : null;
        $dims[ 'y' ] = isset( $raw_dims[ $y_index ] ) ? StringHelper::getFloat( $raw_dims[ $y_index ] ) : null;
        $dims[ 'z' ] = isset( $raw_dims[ $z_index ] ) ? StringHelper::getFloat( $raw_dims[ $z_index ] ) : null;

        return $dims;
    }

    /**
     * Converts weight from grams to pounds
     * @param float|null $g_value Weight in grams
     * @return float|null
     */
    public static function convertLbsFromG( ?float $g_value ): ?float
    {
        return self::convert( $g_value, 0.0022 );
    }

    /**
     * Converts weight from an ounce to pounds
     * @param float|null $g_value Weight in ounces
     * @return float|null
     */
    public static function convertLbsFromOz( ?float $g_value ): ?float
    {
        return self::convert( $g_value, 0.063 );
    }

    /**
     * Converts a number from an arbitrary unit of measurement to an arbitrary unit of measurement
     * @param float|null $value The value of the unit of measurement to be translated
     * @param float $contain_value The value of one unit of measurement relative to another
     * @return float|null
     */
    public static function convert( ?float $value, float $contain_value ): ?float
    {
        return StringHelper::normalizeFloat( $value * $contain_value );
    }
}