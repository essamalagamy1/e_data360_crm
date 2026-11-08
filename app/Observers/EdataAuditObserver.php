<?php

namespace App\Observers;

use App\Models\AttendanceEmployee;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ProductService;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\User;
use App\Support\Edata\Audit;
use App\Support\Edata\Fmt;
use App\Support\Edata\Labels;
use App\Support\Edata\Notifier;
use App\Support\Edata\TaskRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Records every change to the core records and raises the matching in-app
 * notification. Because it sits on the models, screens that were not rebuilt
 * (and any future code path) are covered without extra work.
 */
class EdataAuditObserver
{
    public function created(Model $model): void
    {
        if (!Auth::check()) {
            return;
        }

        [$type, $label] = $this->describe($model);
        if ($type) {
            $action = ($model instanceof AttendanceEmployee && $model->source === 'web') ? 'clock_in' : 'created';
            Audit::forModel($action, $model, $type, $label, null, Audit::clean($model->getAttributes()));
        }

        $this->notifyCreated($model);
    }

    public function updated(Model $model): void
    {
        [$type, $label] = $this->describe($model);
        if (!$type || !Auth::check()) {
            return;
        }

        $new = Audit::clean($model->getChanges());
        $passwordChanged = array_key_exists('password', $model->getChanges());

        if (empty($new) && !$passwordChanged) {
            return;
        }

        $old = array_intersect_key($model->getOriginal(), $new);
        if ($passwordChanged) {
            $new['password_changed'] = 'تم التغيير';
        }

        $action = 'updated';
        if ($model instanceof AttendanceEmployee && $model->source === 'web' && array_key_exists('clock_out', $new)) {
            $action = 'clock_out';
        } elseif (array_key_exists('status', $new) || array_key_exists('stage_id', $new)) {
            $action = 'status_changed';
        } elseif (array_key_exists('archived_at', $new)) {
            $action = $new['archived_at'] ? 'archived' : 'restored';
        }

        Audit::forModel($action, $model, $type, $label, $old, $new);

        $this->notifyUpdated($model, $old, $new);
    }

    public function deleted(Model $model): void
    {
        [$type, $label] = $this->describe($model);
        if (!$type || !Auth::check()) {
            return;
        }

        Audit::forModel('deleted', $model, $type, $label, Audit::clean($model->getAttributes()), null);
    }

    /** @return array{0:?string,1:?string} */
    private function describe(Model $model): array
    {
        return match (true) {
            $model instanceof Project => ['project', $model->project_name],
            $model instanceof ProjectTask => ['task', $model->name],
            $model instanceof Invoice => ['invoice', Fmt::invoiceNumber($model->invoice_id, (int) $model->created_by)],
            $model instanceof InvoicePayment => ['payment', 'دفعة على فاتورة #' . $model->invoice_id . ' بقيمة ' . $model->amount],
            $model instanceof AttendanceEmployee => ['attendance', $this->attendanceLabel($model)],
            $model instanceof User => ['user', $model->name],
            $model instanceof Employee => ['employee', $model->name],
            $model instanceof Customer => ['client', $model->name],
            $model instanceof ProductService => ['product', $model->name],
            $model instanceof Role => ['role', $model->name],
            default => [null, null],
        };
    }

    private function attendanceLabel(AttendanceEmployee $row): string
    {
        $employee = Employee::find($row->employee_id);

        return trim(($employee->name ?? 'موظف') . ' - ' . Labels::date($row->date));
    }

    private function notifyCreated(Model $model): void
    {
        if ($model instanceof ProjectTask) {
            $assignees = TaskRules::assigneeIds($model);
            if ($assignees) {
                Notifier::send($assignees, 'task_assigned', 'أُسندت إليك مهمة جديدة', $model->name, route('edata.tasks.show', $model->id, false));
            }
        }

        if ($model instanceof ProjectUser) {
            $project = Project::find($model->project_id);
            if ($project) {
                Notifier::send((int) $model->user_id, 'project_joined', 'تمت إضافتك إلى مشروع', $project->project_name, route('edata.projects.show', $project->id, false));
            }
        }

        if ($model instanceof Invoice) {
            $company = (int) $model->created_by;
            Notifier::send(Notifier::holders($company, 'manage invoice'), 'invoice_created', 'فاتورة جديدة', Fmt::invoiceNumber($model->invoice_id, $company), route('edata.invoices.show', $model->id, false));
        }

        if ($model instanceof InvoicePayment) {
            $invoice = Invoice::find($model->invoice_id);
            if ($invoice) {
                $company = (int) $invoice->created_by;
                Notifier::send(
                    Notifier::holders($company, 'manage invoice'),
                    'payment_recorded',
                    'تم تسجيل دفعة',
                    Fmt::invoiceNumber($invoice->invoice_id, $company) . ' - ' . Fmt::money($model->amount, $company),
                    route('edata.invoices.show', $invoice->id, false)
                );
            }
        }
    }

    private function notifyUpdated(Model $model, array $old, array $new): void
    {
        if ($model instanceof ProjectTask && array_key_exists('assign_to', $new)) {
            $before = array_filter(explode(',', (string) ($old['assign_to'] ?? '')));
            $after = array_filter(explode(',', (string) $new['assign_to']));
            $added = array_map('intval', array_diff($after, $before));
            if ($added) {
                Notifier::send($added, 'task_assigned', 'أُسندت إليك مهمة', $model->name, route('edata.tasks.show', $model->id, false));
            }
        }

        if ($model instanceof Project && array_key_exists('status', $new)) {
            $team = ProjectUser::where('project_id', $model->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if ($model->manager_id) {
                $team[] = (int) $model->manager_id;
            }
            Notifier::send($team, 'project_status', 'تغيرت حالة مشروع إلى ' . Labels::project($new['status'])[0], $model->project_name, route('edata.projects.show', $model->id, false));
        }

        if ($model instanceof Project && array_key_exists('manager_id', $new) && $new['manager_id']) {
            Notifier::send((int) $new['manager_id'], 'project_manager', 'أصبحت مديراً لمشروع', $model->project_name, route('edata.projects.show', $model->id, false));
        }
    }
}
