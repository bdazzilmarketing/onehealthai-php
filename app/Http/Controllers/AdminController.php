<?php

namespace App\Http\Controllers;

use App\Admin\AdminData;
use App\Admin\AdminUsers;
use App\Admin\AiQuotaAdmin;
use App\Auth\Roles;
use App\Auth\Staff;
use App\Calculator\LabMeta;
use App\Database\Db;
use App\Database\Tx;
use App\Http\Support\Input;
use App\Http\Support\ServerFn;
use App\Storage\SignedLinks;
use App\Submissions\SubmissionAdmin;
use App\Support\AppError;
use App\Support\Audit;
use App\Support\InvalidInput;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Admin Portal endpoints (port of src/lib/admin.functions.ts). Each method
 * serves /api/fn/<method name> behind the staff guard, checks the caller's
 * role first and answers the same JSON as the original server function.
 * Changes are refused for the read-only security auditor role: anything that
 * writes asks for requireAdmin or requireSuperAdmin.
 */
final class AdminController
{
    public function __construct(
        private readonly AdminData $data,
        private readonly AdminUsers $users,
        private readonly SubmissionAdmin $submissions,
        private readonly AiQuotaAdmin $quota,
        private readonly Audit $audit,
        private readonly SignedLinks $links,
    ) {}

    // --------------------------------------------------------------- session

    public function getSession(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            $isAdmin = Roles::isUserAdmin($staff->db, $staff->userId);
            $superAdmin = Roles::isSuperAdmin($staff->db, $staff->userId);
            $auditor = Roles::isSecurityAuditor($staff->db, $staff->userId);

            // `admin` grants portal access; `readOnly` marks a security auditor without write roles.
            return [
                'admin' => $isAdmin || $auditor,
                'superAdmin' => $superAdmin,
                'readOnly' => ! $isAdmin && $auditor,
                'email' => $staff->email,
                'profile' => $this->data->getCurrentUserProfile($staff->db, $staff->userId),
                'mfa' => $staff->mfa,
            ];
        });
    }

    /** Records a successful sign-in in the audit log. */
    public function recordSignIn(Request $request): Response
    {
        return self::answer($request, fn (Staff $staff) => $this->users->recordLogin($staff->userId, $staff->email));
    }

    // ------------------------------------------- user management (super admin)

    public function listUsers(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireUserListViewer($staff->db, $staff->userId);

            return $this->users->list();
        });
    }

    public function createUser(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $data = [
                'email' => $input->email('email'),
                'sendInvite' => $input->bool('sendInvite', true),
                'temporaryPassword' => $input->optionalString('temporaryPassword', 12, 200),
                'role' => $input->enum('role', ['admin', 'security_auditor'], 'admin'),
            ];
            Roles::requireSuperAdmin($staff->db, $staff->userId);

            return $this->users->create($staff->userId, $staff->email, $data);
        });
    }

    public function setUserEnabled(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            [$userId, $enabled] = [$input->guid('userId'), $input->bool('enabled')];
            Roles::requireSuperAdmin($staff->db, $staff->userId);

            return $this->users->setEnabled($staff->userId, $staff->email, $userId, $enabled);
        });
    }

    /** Super Admin only: resend the invitation or send a password reset to an existing account. */
    public function sendUserAccountEmail(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            [$userId, $kind] = [$input->guid('userId'), $input->enum('kind', ['invite', 'reset'])];
            Roles::requireSuperAdmin($staff->db, $staff->userId);

            return $this->users->sendAccountEmail($staff->userId, $staff->email, $userId, $kind);
        });
    }

    // -------------------------------------------------------------- overview

    public function getDashboard(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);
            $prompts = $this->data->listPrompts($staff->db);
            $active = null;
            foreach ($prompts as $prompt) {
                if ($prompt['status'] === 'active') {
                    $active = $prompt;
                    break;
                }
            }

            return [
                'versions' => array_slice($this->data->listDatasetVersions($staff->db), 0, 10),
                'published' => $this->data->getPublishedVersions($staff->db),
                'settings' => $this->data->getSettings($staff->db),
                'activePrompt' => $active,
                'audit' => $this->data->listAuditLog($staff->db, 10),
                'integrations' => $this->data->getIntegrationStatus(),
            ];
        });
    }

    // --------------------------------------------------------------- imports

    public function uploadWorkbook(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $bytes = base64_decode($input->string('fileBase64', 1), true);
            if ($bytes === false) {
                throw new InvalidInput('fileBase64', 'must be base64');
            }
            $data = [
                'workbookType' => $input->enum('workbookType', LabMeta::TYPES),
                'filename' => $input->string('filename', 1, 255),
                'bytes' => $bytes,
                'notes' => $input->optionalString('notes', 0, 2000),
            ];
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->data->importWorkbook($staff->db, $staff->userId, $staff->email, $data);
        });
    }

    public function listVersions(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->data->listDatasetVersions($staff->db);
        });
    }

    public function getVersionDetail(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            Roles::requireAdminViewer($staff->db, $staff->userId);
            $tree = $this->data->getDatasetTree($staff->db, $id);

            return [
                'tree' => $tree,
                'diff' => $tree['version'] ? $this->data->compareWithPublished($staff->db, $id, $tree['version']['workbook_type']) : null,
            ];
        });
    }

    public function getPublishedData(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $type = $input->enum('workbookType', LabMeta::TYPES);
            Roles::requireAdminViewer($staff->db, $staff->userId);
            foreach ($this->data->getPublishedVersions($staff->db) as $version) {
                if ($version['workbook_type'] === $type) {
                    return ['tree' => $this->data->getDatasetTree($staff->db, $version['id'])];
                }
            }

            return ['tree' => null];
        });
    }

    public function publishVersionFn(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            $rollback = $input->has('rollback') ? $input->bool('rollback') : false;
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->data->publishVersion($staff->db, $staff->userId, $staff->email, $id, $rollback);
        });
    }

    // -------------------------------------------------------------- settings

    public function getAdminSettings(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return ['settings' => $this->data->getSettings($staff->db), 'integrations' => $this->data->getIntegrationStatus()];
        });
    }

    public function saveSetting(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            [$key, $value] = [$input->enum('key', ['calculator_variance', 'ai_model']), $input->scalarRecord('value')];
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->data->updateSetting($staff->db, $staff->userId, $staff->email, $key, $value);
        });
    }

    // ------------------------------------------------------------ AI prompts

    public function listPromptVersions(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->data->listPrompts($staff->db);
        });
    }

    public function savePromptVersion(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $data = [
                'promptName' => $input->string('promptName', 1, 200),
                'promptText' => $input->string('promptText', 1, 20000),
                'model' => $input->string('model', 1, 100),
                'activate' => $input->bool('activate', false),
            ];
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->data->createPromptVersion($staff->db, $staff->userId, $staff->email, $data);
        });
    }

    public function activatePromptVersion(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->data->activatePrompt($staff->db, $staff->userId, $staff->email, $id);
        });
    }

    // ----------------------------------------------------------------- audit

    public function getAuditLog(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->data->listAuditLog($staff->db, 300);
        });
    }

    // ----------------------------------------------------------- submissions

    public function listCalculatorSubmissions(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->submissions->list();
        });
    }

    public function getCalculatorSubmission(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->submissions->detail($id);
        });
    }

    public function retryMakeDelivery(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->submissions->retryDelivery($id, $staff->userId, $staff->email);
        });
    }

    // --------------------------------------------------- native sales reports

    public function listSalesRecipients(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);
            try {
                return $staff->db->run(fn (Tx $tx) => $tx->rows(
                    'select id, email, active, created_at from public.sales_report_recipients order by created_at',
                ));
            } catch (\Throwable) {
                throw new AppError('Unable to load recipients');
            }
        });
    }

    public function addSalesRecipient(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $email = $input->email('email', normalize: true, max: 254);
            Roles::requireAdmin($staff->db, $staff->userId);
            // The insert runs as the signed-in user, so row-level security enforces the admin-only rule too.
            try {
                $staff->db->run(fn (Tx $tx) => $tx->execute(
                    'insert into public.sales_report_recipients (email, created_by) values (?, ?)', [$email, $staff->userId],
                ));
            } catch (\Throwable $error) {
                throw new AppError(Db::errorCode($error) === '23505' ? 'This email is already a recipient' : 'Unable to add recipient');
            }
            $this->audit->record('sales_recipient_added', $staff->userId, $staff->email, ['email' => $email]);

            return ['ok' => true];
        });
    }

    public function removeSalesRecipient(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $id = $input->guid('id');
            Roles::requireAdmin($staff->db, $staff->userId);
            try {
                $removed = $staff->db->run(fn (Tx $tx) => $tx->select(
                    'delete from public.sales_report_recipients where id = ? returning email', [$id],
                ));
            } catch (\Throwable) {
                $removed = [];
            }
            if (count($removed) !== 1) {
                throw new AppError('Unable to remove recipient');
            }
            $this->audit->record('sales_recipient_removed', $staff->userId, $staff->email, ['email' => $removed[0]['email']]);

            return ['ok' => true];
        });
    }

    /**
     * Short-lived (60 s) signed link to the internal report for an authorised
     * admin viewer. The download route checks the signature and the viewer's
     * session again.
     */
    public function getSubmissionReportUrl(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            [$id, $download] = [$input->guid('id'), $input->bool('download', false)];
            Roles::requireAdminViewer($staff->db, $staff->userId);
            $row = $this->submissions->detail($id);
            if (! $row || $row['report_status'] !== 'generated' || ! $row['report_storage_path']) {
                throw new AppError('Report is not available');
            }

            return ['url' => $this->links->signedReportPath($row['id'], $download)];
        });
    }

    // -------------------------------------------------------------- AI quota

    public function getAiQuotaOverview(Request $request): Response
    {
        return self::answer($request, function (Staff $staff) {
            Roles::requireAdminViewer($staff->db, $staff->userId);

            return $this->quota->overview($staff->db);
        });
    }

    public function updateAiQuotaSettings(Request $request): Response
    {
        return self::answer($request, function (Staff $staff, Input $input) {
            $data = ['enabled' => $input->bool('enabled')];
            foreach (AiQuotaAdmin::LIMITS as $name => [$min, $max]) {
                $data[$name] = $input->int($name, $min, $max);
            }
            // Nothing but the known settings is accepted.
            foreach ($input->keys() as $key) {
                if (! array_key_exists($key, $data)) {
                    throw new InvalidInput($key, 'is not a setting');
                }
            }
            Roles::requireAdmin($staff->db, $staff->userId);

            return $this->quota->update($staff->db, $staff->userId, $staff->email, $data);
        });
    }

    /**
     * Runs a handler with the signed-in staff member and, when the handler
     * asks for it, the request's JSON input.
     *
     * @param  callable(Staff, Input): mixed  $handler
     */
    private static function answer(Request $request, callable $handler): Response
    {
        return ServerFn::respond(function () use ($request, $handler) {
            $wantsInput = (new \ReflectionFunction($handler(...)))->getNumberOfParameters() > 1;

            return $wantsInput
                ? $handler($request->attributes->get('staff'), Input::from($request))
                : $handler($request->attributes->get('staff'));
        });
    }
}
