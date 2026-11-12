<?php

namespace App\Support\Edata;

use Carbon\Carbon;

/**
 * Status vocabulary shown across the system. Each entry has the text the user
 * reads and a tone the stylesheet maps to one colour.
 */
class Labels
{
    /** Carbon dayOfWeek => Arabic name. */
    public const WEEKDAYS = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];

    /** «دوام كامل» or «دوام جزئي: الأحد، الثلاثاء». */
    public static function schedule(?array $days): string
    {
        if (!$days) {
            return __('دوام كامل');
        }

        return __('دوام جزئي') . ': ' . implode('، ', array_map(fn ($d) => __(self::WEEKDAYS[$d] ?? ''), $days));
    }

    public const PROJECT_STATUSES = [
        'new' => ['جديد', 'neutral'],
        'planning' => ['قيد التخطيط', 'info'],
        'in_progress' => ['قيد التنفيذ', 'primary'],
        'waiting_client' => ['بانتظار العميل', 'warn'],
        'on_hold' => ['متوقف', 'muted'],
        'complete' => ['مكتمل', 'good'],
        'canceled' => ['ملغي', 'bad'],
    ];

    public const PROJECT_OPEN = ['new', 'planning', 'in_progress', 'waiting_client', 'on_hold'];

    public const TASK_PRIORITIES = [
        'critical' => ['عاجلة', 'bad'],
        'high' => ['عالية', 'warn'],
        'medium' => ['متوسطة', 'info'],
        'low' => ['منخفضة', 'muted'],
    ];

    public const INVOICE_STATES = [
        'draft' => ['مسودة', 'muted'],
        'sent' => ['قيد الدفع', 'info'],
        'partial' => ['مدفوعة جزئياً', 'warn'],
        'paid' => ['تم التحصيل', 'good'],
        'overdue' => ['متأخرة', 'bad'],
        'cancelled' => ['ملغاة', 'neutral'],
    ];

    public const ATTENDANCE_STATES = [
        'working' => ['يعمل الآن', 'primary'],
        'present' => ['حاضر', 'good'],
        'late' => ['متأخر', 'warn'],
        'absent' => ['غائب', 'bad'],
        'leave' => ['إجازة', 'info'],
        'holiday' => ['عطلة', 'muted'],
        'weekend' => ['راحة أسبوعية', 'neutral'],
        'overtime' => ['عمل إضافي', 'primary'],
        'missing_out' => ['لم يسجل الانصراف', 'bad'],
        'not_yet' => ['لم يسجل بعد', 'neutral'],
        'permission' => ['بإذن', 'info'],
    ];

    public const CLIENT_STATUSES = [
        'active' => ['نشط', 'good'],
        'prospect' => ['محتمل', 'info'],
        'paused' => ['متوقف', 'muted'],
    ];

    public const EMPLOYMENT_STATUSES = [
        'active' => ['على رأس العمل', 'good'],
        'on_leave' => ['في إجازة', 'info'],
        'probation' => ['تحت التجربة', 'warn'],
        'ended' => ['انتهت خدمته', 'neutral'],
    ];

    public const AUDIT_ACTIONS = [
        'created' => ['إضافة', 'good'],
        'updated' => ['تعديل', 'info'],
        'deleted' => ['حذف', 'bad'],
        'status_changed' => ['تغيير حالة', 'primary'],
        'archived' => ['أرشفة', 'muted'],
        'restored' => ['استعادة', 'info'],
        'clock_in' => ['تسجيل حضور', 'good'],
        'clock_out' => ['تسجيل انصراف', 'info'],
    ];

    /** Readable names for the fields shown in the audit log. */
    public const FIELDS = [
        'project_name' => 'اسم المشروع', 'name' => 'الاسم', 'status' => 'الحالة', 'budget' => 'القيمة',
        'start_date' => 'تاريخ البداية', 'end_date' => 'تاريخ التسليم', 'description' => 'الوصف',
        'customer_id' => 'العميل', 'manager_id' => 'مدير المشروع', 'manual_progress' => 'نسبة الإنجاز',
        'priority' => 'الأولوية', 'assign_to' => 'المسؤول', 'stage_id' => 'المرحلة', 'project_id' => 'المشروع',
        'is_complete' => 'مكتملة', 'cancelled_at' => 'تاريخ الإلغاء', 'progress' => 'نسبة الإنجاز',
        'issue_date' => 'تاريخ الإصدار', 'due_date' => 'تاريخ الاستحقاق', 'notes' => 'ملاحظات',
        'amount' => 'المبلغ', 'date' => 'التاريخ', 'reference' => 'المرجع', 'clock_in' => 'وقت الدخول',
        'clock_out' => 'وقت الخروج', 'email' => 'البريد الإلكتروني', 'contact' => 'الهاتف',
        'phone' => 'الهاتف', 'type' => 'الدور', 'crm_status' => 'حالة العميل', 'archived_at' => 'تاريخ الأرشفة',
        'industry' => 'نوع النشاط', 'source' => 'المصدر', 'contact_person' => 'الشخص المسؤول',
        'sale_price' => 'السعر', 'is_active' => 'مفعّل', 'category_id' => 'التصنيف',
        'designation_id' => 'المسمى الوظيفي', 'department_id' => 'القسم', 'company_doj' => 'تاريخ التعيين',
        'employment_status' => 'الحالة الوظيفية', 'billing_country' => 'الدولة', 'billing_city' => 'المدينة',
        'password_changed' => 'كلمة المرور', 'worked_seconds' => 'ساعات العمل',
    ];

    public static function project(?string $status): array
    {
        return self::PROJECT_STATUSES[$status] ?? [$status ?: 'غير محدد', 'neutral'];
    }

    public static function priority(?string $priority): array
    {
        return self::TASK_PRIORITIES[$priority] ?? self::TASK_PRIORITIES['medium'];
    }

    public static function invoice(string $state): array
    {
        return self::INVOICE_STATES[$state] ?? [$state, 'neutral'];
    }

    public static function attendance(string $state): array
    {
        return self::ATTENDANCE_STATES[$state] ?? [$state, 'neutral'];
    }

    public static function client(?string $status): array
    {
        return self::CLIENT_STATUSES[$status] ?? self::CLIENT_STATUSES['active'];
    }

    public static function employment(?string $status): array
    {
        return self::EMPLOYMENT_STATUSES[$status] ?? self::EMPLOYMENT_STATUSES['active'];
    }

    public static function auditAction(string $action): array
    {
        return self::AUDIT_ACTIONS[$action] ?? [$action, 'neutral'];
    }

    public static function field(string $field): string
    {
        return self::FIELDS[$field] ?? $field;
    }

    /** Seconds as "7 س 25 د", the format used everywhere hours are shown. */
    public static function hours(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $h = __('س');
        $m = __('د');
        $space = app()->getLocale() === 'en' ? '' : ' ';

        if ($hours === 0 && $minutes === 0) {
            return '0' . $space . $m;
        }

        return ($hours ? $hours . $space . $h : '') . ($hours && $minutes ? ' ' : '') . ($minutes ? $minutes . $space . $m : '');
    }

    public static function date($date, string $format = 'j M Y'): string
    {
        if (empty($date) || $date === '0000-00-00') {
            return '—';
        }

        try {
            return Carbon::parse($date)->locale(app()->getLocale())->translatedFormat($format);
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $second = mb_substr($parts[1] ?? '', 0, 1);

        return $first . ($second ? ' ' . $second : '');
    }
}
