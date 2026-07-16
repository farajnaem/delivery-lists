<?php

declare(strict_types=1);

namespace App;

/**
 * قوالب رسائل SMS.
 */
final class MessageTemplates
{
    private const INVITATION_CENTER = 'مركز الإرشاد التربوي REC بالتعاون مع IOM';

    /** رسالة الموعد قبل الاستلام */
    public static function appointment(
        array $campaign,
        string $name,
        string $date,
        string $disbursementCode,
        int $window,
        string $timeFrom = '',
        string $timeTo = ''
    ): string {
        $timeFrom = substr(trim($timeFrom), 0, 5);
        $timeTo = substr(trim($timeTo), 0, 5);
        $timePart = ($timeFrom !== '' && $timeTo !== '')
            ? sprintf(' ، من الساعة %s إلى %s', $timeFrom, $timeTo)
            : '';

        $warehouse = trim((string) ($campaign['warehouse_name'] ?? ''));
        $placePart = $warehouse !== '' ? sprintf(' في %s', $warehouse) : '';

        return sprintf(
            'السيد/ %s يدعوكم %s لاستلام %s وذلك يوم %s%s ، شباك رقم %d%s ، كود رقم %s',
            trim($name),
            self::INVITATION_CENTER,
            trim($campaign['parcel_name'] ?? 'الطرد'),
            $date,
            $placePart,
            $window,
            $timePart,
            ParcelCodeHelper::displayForBeneficiary(
                $disbursementCode,
                (string) ($campaign['parcel_code_suffix'] ?? ''),
                (string) ($campaign['parcel_code'] ?? '')
            )
        );
    }

    /** رسالة تأكيد التسليم بعد الاستلام */
    public static function deliveryConfirmation(array $campaign, array $beneficiary): string
    {
        $name = trim($beneficiary['name'] ?? '');
        $parcel = trim($campaign['parcel_name'] ?? 'الطرد');
        $code = trim((string) ($beneficiary['disbursement_code'] ?? ''));

        $message = sprintf(
            'السيد/ %s يؤكد %s استلام %s',
            $name,
            self::INVITATION_CENTER,
            $parcel
        );

        if ($code !== '') {
            $message .= ' ، كود رقم ' . ParcelCodeHelper::displayForBeneficiary(
                $code,
                (string) ($campaign['parcel_code_suffix'] ?? ''),
                (string) ($campaign['parcel_code'] ?? '')
            );
        }

        return $message;
    }
}
