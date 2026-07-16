<?php

declare(strict_types=1);

namespace App;

/**
 * قوالب رسائل SMS.
 */
final class MessageTemplates
{
    private const INVITATION_CENTER = 'مركز الإرشاد التربوي REC بالتعاون مع IOM';

    /** رسالة الموعد قبل الاستلام — اسم المخزن إلزامي في كل رسالة. */
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

        $warehouse = self::warehouseLabel($campaign);

        return sprintf(
            'السيد/ %s يدعوكم %s لاستلام %s وذلك يوم %s في %s ، شباك رقم %d%s ، كود رقم %s',
            trim($name),
            self::INVITATION_CENTER,
            trim($campaign['parcel_name'] ?? 'الطرد'),
            $date,
            $warehouse,
            $window,
            $timePart,
            ParcelCodeHelper::displayForBeneficiary(
                $disbursementCode,
                (string) ($campaign['parcel_code_suffix'] ?? ''),
                (string) ($campaign['parcel_code'] ?? '')
            )
        );
    }

    /** إعادة بناء رسالة الموعد من صف مستفيد (للتصدير حتى لو النص القديم قديم). */
    public static function appointmentFromBeneficiary(array $campaign, array $beneficiary): string
    {
        return self::appointment(
            $campaign,
            (string) ($beneficiary['name'] ?? ''),
            (string) ($beneficiary['delivery_date'] ?? ''),
            (string) ($beneficiary['disbursement_code'] ?? ''),
            (int) ($beneficiary['window_num'] ?? 0),
            (string) ($beneficiary['time_from'] ?? ''),
            (string) ($beneficiary['time_to'] ?? '')
        );
    }

    /** رسالة تأكيد التسليم بعد الاستلام — تتضمن اسم المخزن دائماً. */
    public static function deliveryConfirmation(array $campaign, array $beneficiary): string
    {
        $name = trim($beneficiary['name'] ?? '');
        $parcel = trim($campaign['parcel_name'] ?? 'الطرد');
        $code = trim((string) ($beneficiary['disbursement_code'] ?? ''));
        $warehouse = self::warehouseLabel($campaign);

        $message = sprintf(
            'السيد/ %s يؤكد %s استلام %s في %s',
            $name,
            self::INVITATION_CENTER,
            $parcel,
            $warehouse
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

    /** اسم المخزن يظهر في كل رسالة؛ لا يُترك فارغاً. */
    private static function warehouseLabel(array $campaign): string
    {
        $warehouse = trim((string) ($campaign['warehouse_name'] ?? ''));
        if ($warehouse === '') {
            throw new \RuntimeException('اسم المخزن مطلوب في الرسالة.');
        }

        return $warehouse;
    }
}
