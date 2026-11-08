<?php

namespace App\Support\Edata;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides which rows a user may reach.
 *
 * Every rebuilt screen and endpoint starts its query here, so a user who types
 * another record's URL gets the same answer as if the record did not exist.
 */
class Access
{
    public const ALL = 'all';
    public const TEAM = 'team';
    public const OWN = 'own';

    private static array $scopeCache = [];

    public static function isOwner(User $user): bool
    {
        return in_array($user->type, ['company', 'super admin'], true);
    }

    public static function scope(User $user, string $module): string
    {
        if (self::isOwner($user)) {
            return self::ALL;
        }

        $key = $user->id . ':' . $module;
        if (isset(self::$scopeCache[$key])) {
            return self::$scopeCache[$key];
        }

        $scopes = DB::table('edata_role_scopes')
            ->whereIn('role_id', $user->roles->pluck('id'))
            ->where('module', $module)
            ->pluck('scope');

        // A user with several roles gets the widest one. No row at all means the narrowest.
        $scope = $scopes->contains(self::ALL) ? self::ALL : ($scopes->contains(self::TEAM) ? self::TEAM : self::OWN);

        return self::$scopeCache[$key] = $scope;
    }

    public static function flushCache(): void
    {
        self::$scopeCache = [];
    }

    /** Aborts with 403 unless the user holds at least one of the given permissions. */
    public static function need(User $user, string|array $permissions): void
    {
        foreach ((array) $permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403);
    }

    public static function any(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function projects(User $user): Builder
    {
        $query = Project::query()->where('projects.created_by', $user->creatorId());

        // Without a view permission, a user still reaches the projects they belong to.
        $canView = self::any($user, ['manage project', 'view project']);

        if (!$canView || self::scope($user, 'projects') !== self::ALL) {
            $query->where(function ($where) use ($user) {
                $where->where('projects.manager_id', $user->id)
                    ->orWhereIn('projects.id', ProjectUser::where('user_id', $user->id)->select('project_id'));
            });
        }

        return $query;
    }

    public static function tasks(User $user): Builder
    {
        $company = Project::where('created_by', $user->creatorId())->select('id');
        $query = ProjectTask::query()->whereIn('project_tasks.project_id', $company);

        // Every employee sees the tasks assigned to them, whatever their role says.
        $canView = self::any($user, ['manage project task', 'view project task']);

        switch ($canView ? self::scope($user, 'tasks') : self::OWN) {
            case self::ALL:
                break;
            case self::TEAM:
                $query->where(function ($where) use ($user) {
                    $where->whereIn('project_tasks.project_id', self::projects($user)->select('projects.id'))
                        ->orWhereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$user->id]);
                });
                break;
            default:
                $query->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$user->id]);
        }

        return $query;
    }

    public static function customers(User $user): Builder
    {
        $query = Customer::query()->where('customers.created_by', $user->creatorId());

        if (self::scope($user, 'clients') !== self::ALL) {
            $query->whereIn('customers.id', self::projects($user)->whereNotNull('projects.customer_id')->select('projects.customer_id'));
        }

        return $query;
    }

    public static function invoices(User $user): Builder
    {
        $query = Invoice::query()->where('invoices.created_by', $user->creatorId());

        if (self::scope($user, 'invoices') !== self::ALL) {
            $query->whereIn('invoices.project_id', self::projects($user)->select('projects.id'));
        }

        return $query;
    }

    /** Employees whose attendance this user may see. */
    public static function employees(User $user, string $module = 'attendance'): Builder
    {
        $query = Employee::query()->where('employees.created_by', $user->creatorId());
        $scope = self::scope($user, $module);

        if ($scope === self::ALL) {
            return $query;
        }

        if ($scope === self::TEAM) {
            $projectIds = self::projects($user)->select('projects.id');
            $teammates = ProjectUser::whereIn('project_id', $projectIds)->select('user_id');

            return $query->where(function ($where) use ($user, $teammates) {
                $where->where('employees.user_id', $user->id)->orWhereIn('employees.user_id', $teammates);
            });
        }

        return $query->where('employees.user_id', $user->id);
    }

    /** Company staff, for pickers. Never includes clients or the platform admin. */
    public static function staff(User $user): Builder
    {
        return User::query()
            ->where(function ($where) use ($user) {
                $where->where('created_by', $user->creatorId())->orWhere('id', $user->creatorId());
            })
            ->whereNotIn('type', ['client', 'super admin']);
    }
}
