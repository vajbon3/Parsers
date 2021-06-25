<?php

namespace App\Helpers;

class FeedHelper
{
    /**
     * Очищает пустые элементы из массива особенностей товара
     * @param array $short_desc Особенности товара
     * @return array Очещенные особенности
     */
    public static function normalizeShortDesc( array $short_desc ): array
    {
        $short_desc = array_map( static fn( $desc ) => StringHelper::normalizeSpaceInString( $desc ), $short_desc );
        return array_filter( $short_desc, static fn( $desc ) => str_replace( ' ', '', $desc ) );
    }

    /**
     * Получает размеры товара из строки
     * @param string $string Строка, содержащая размеры
     * @param string $separator Разделитель, с помощью которого строка преобразуется в массив с размерами товара
     * @param int $x_index Индекс размера в массиве по оси X
     * @param int $y_index Индекс размера в массиве по оси Y
     * @param int $z_index Индекс размера в массиве по оси Z
     * @return array Массив, содержащий размеры товара
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
     * Конвертирует вес из грамм в фунты
     * @param float|null $g_value Вес в граммах
     * @return float|null
     */
    public static function convertLbsFromG( ?float $g_value ): ?float
    {
        return self::convert( $g_value, 0.0022 );
    }

    /**
     * Конвертирует вес из унции в фунты
     * @param float|null $g_value Вес в унции
     * @return float|null
     */
    public static function convertLbsFromOz( ?float $g_value ): ?float
    {
        return self::convert( $g_value, 0.063 );
    }

    /**
     * Конвертирует число из произвольной единицы измерения в произвольную единицу измерения
     * @param float|null $value Значение единицы измерения которую необходимо перевести
     * @param float $contain_value Значение одной единицы измерения по отношению к другой
     * @return float|null
     */
    public static function convert( ?float $value, float $contain_value ): ?float
    {
        return StringHelper::normalizeFloat( $value * $contain_value );
    }
}
