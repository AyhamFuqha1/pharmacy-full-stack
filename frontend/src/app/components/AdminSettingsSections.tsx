import { useEffect, useState } from "react";
import { Pencil, Plus } from "lucide-react";
import { accountingPeriodsApi, branchesApi, usersApi } from "../../api/admin";
import { ApiClientError } from "../../api/client";
import type {
  AccountingPeriodDto,
  AccountingPeriodPayload,
  AdminRoleDto,
  AdminUserDto,
  BranchDto,
  BranchPayload,
} from "../../types/admin";
import { formatDateOnly } from "../../utils/batchFormatters";
import { useAuth } from "../../auth/useAuth";
import { useTranslation } from "react-i18next";

const unexpected = () =>
  new ApiClientError(
    500,
    "An unexpected server error occurred. Please try again.",
  );

export function AccountingPeriodsSection() {
  const [rows, setRows] = useState<AccountingPeriodDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [saving, setSaving] = useState(false),
    [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<AccountingPeriodPayload>({
      name: "",
      start_date: "",
      end_date: "",
    }),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    accountingPeriodsApi
      .list(page, 15, controller.signal)
      .then((response) => {
        setRows(response.data);
        setLastPage(response.meta.last_page ?? 1);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(value instanceof ApiClientError ? value : unexpected());
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, reload]);
  async function create(e: React.FormEvent) {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (!form.name.trim()) errors.name = "Name is required.";
    if (!form.start_date) errors.start_date = "Start date is required.";
    if (!form.end_date) errors.end_date = "End date is required.";
    if (form.start_date && form.end_date && form.end_date < form.start_date)
      errors.end_date = "End date cannot be before start date.";
    setFieldErrors(errors);
    if (Object.keys(errors).length) return;
    setSaving(true);
    setError(null);
    try {
      await accountingPeriodsApi.create(form);
      setSuccess("Accounting period created and opened.");
      setShowForm(false);
      setForm({ name: "", start_date: "", end_date: "" });
      setReload((v) => v + 1);
    } catch (value) {
      const requestError =
        value instanceof ApiClientError ? value : unexpected();
      setError(requestError);
      setFieldErrors(firstErrors(requestError));
    } finally {
      setSaving(false);
    }
  }
  async function close(period: AccountingPeriodDto) {
    if (
      !window.confirm(
        `Close accounting period "${period.name}"? Posting will remain blocked until an authorized reopen.`,
      )
    )
      return;
    setError(null);
    try {
      await accountingPeriodsApi.close(period.id);
      setSuccess(`Accounting period ${period.name} closed.`);
      setReload((v) => v + 1);
    } catch (value) {
      setError(value instanceof ApiClientError ? value : unexpected());
    }
  }
  async function reopen(period: AccountingPeriodDto) {
    const reason = window.prompt(`Reason for reopening "${period.name}"`);
    if (
      !reason?.trim() ||
      !window.confirm(
        "Reopen this period? Existing journal entries will be preserved.",
      )
    )
      return;
    setError(null);
    try {
      await accountingPeriodsApi.reopen(period.id, reason.trim());
      setSuccess(
        `Accounting period ${period.name} reopened. Existing journals were preserved.`,
      );
      setReload((v) => v + 1);
    } catch (value) {
      setError(value instanceof ApiClientError ? value : unexpected());
    }
  }
  return (
    <div>
      <Feedback
        error={error}
        success={success}
        context="Accounting period"
        retry={() => setReload((v) => v + 1)}
      />
      <div className="flex justify-end mb-4">
        <button
          onClick={() => setShowForm((v) => !v)}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> New Period
        </button>
      </div>
      {showForm && (
        <form
          onSubmit={(e) => void create(e)}
          className="bg-white border rounded-2xl p-6 mb-6"
        >
          <h2 className="text-xl font-bold mb-5">Open accounting period</h2>
          <div className="grid md:grid-cols-3 gap-4">
            <Field label="Name" error={fieldErrors.name}>
              <input
                className="input"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </Field>
            <Field label="Start date" error={fieldErrors.start_date}>
              <input
                type="date"
                className="input"
                value={form.start_date}
                onChange={(e) =>
                  setForm({ ...form, start_date: e.target.value })
                }
              />
            </Field>
            <Field label="End date" error={fieldErrors.end_date}>
              <input
                type="date"
                className="input"
                value={form.end_date}
                onChange={(e) => setForm({ ...form, end_date: e.target.value })}
              />
            </Field>
          </div>
          <button
            disabled={saving}
            className="mt-5 px-5 py-2.5 bg-teal-600 text-white rounded-xl disabled:opacity-50"
          >
            {saving ? "Creating…" : "Create Period"}
          </button>
        </form>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <State text="Loading accounting periods…" />
        ) : rows.length === 0 ? (
          <State text="No accounting periods found." />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-4">Name</th>
                <th>Year / Month</th>
                <th>Dates</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="p-4 font-semibold">{row.name}</td>
                  <td>
                    {row.period_year} /{" "}
                    {row.period_month === 0 ? "Full year" : row.period_month}
                  </td>
                  <td>
                    {formatDateOnly(row.start_date)} –{" "}
                    {formatDateOnly(row.end_date)}
                  </td>
                  <td>
                    <Status value={row.status} />
                  </td>
                  <td>
                    {row.status === "open" && (
                      <button
                        onClick={() => void close(row)}
                        className="text-red-600"
                      >
                        Close
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}

export function UsersSection() {
  const { t } = useTranslation();
  const { hasPermission } = useAuth();
  const canReadBranches = hasPermission("branches.manage");
  const [rows, setRows] = useState<AdminUserDto[]>([]),
    [roles, setRoles] = useState<AdminRoleDto[]>([]),
    [branches, setBranches] = useState<BranchDto[]>([]);
  const [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [search, setSearch] = useState(""),
    [appliedSearch, setAppliedSearch] = useState(""),
    [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [saving, setSaving] = useState(false),
    [editing, setEditing] = useState<AdminUserDto | "new" | null>(null);
  const [form, setForm] = useState({
      name: "",
      email: "",
      role: "",
      branch_id: "",
    }),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    Promise.all([
      usersApi.list(
        { page, per_page: 15, search: appliedSearch },
        controller.signal,
      ),
      usersApi.roles(controller.signal),
      canReadBranches ? loadBranches(controller.signal) : Promise.resolve([]),
    ])
      .then(([users, roleResponse, branchRows]) => {
        setRows(users.data);
        setLastPage(users.meta.last_page ?? 1);
        setRoles(roleResponse.data);
        setBranches(branchRows);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(value instanceof ApiClientError ? value : unexpected());
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, appliedSearch, reload, canReadBranches]);
  async function open(user?: AdminUserDto) {
    setFieldErrors({});
    setError(null);
    if (!user) {
      setEditing("new");
      setForm({ name: "", email: "", role: "", branch_id: "" });
      return;
    }
    setSaving(true);
    try {
      const response = await usersApi.detail(user.id);
      setEditing(response.data);
      setForm({
        name: response.data.name,
        email: response.data.email,
        role: String(response.data.role?.id ?? ""),
        branch_id: "",
      });
    } catch (value) {
      setError(value instanceof ApiClientError ? value : unexpected());
    } finally {
      setSaving(false);
    }
  }
  async function save(e: React.FormEvent) {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (form.name.trim().length < 3)
      errors.name = "Name must be at least 3 characters.";
    if (!form.email.includes("@")) errors.email = "A valid email is required.";
    if (!form.role) errors.role = "Role is required.";
    setFieldErrors(errors);
    if (Object.keys(errors).length) return;
    setSaving(true);
    setError(null);
    try {
      const response =
        editing === "new"
          ? await usersApi.create({
              name: form.name.trim(),
              email: form.email.trim(),
              role: Number(form.role),
              branch_id: form.branch_id ? Number(form.branch_id) : null,
            })
          : editing
            ? await usersApi.update(editing.id, {
                name: form.name.trim(),
                email: form.email.trim(),
                role: Number(form.role),
              })
            : null;
      if (response) setSuccess(t(editing === "new" ? "userCreated" : "userUpdated", { name: response.data.name }));
      setEditing(null);
      setReload((v) => v + 1);
    } catch (value) {
      const requestError =
        value instanceof ApiClientError ? value : unexpected();
      setError(requestError);
      setFieldErrors(firstErrors(requestError));
    } finally {
      setSaving(false);
    }
  }
  return (
    <div>
      <Feedback
        error={error}
        success={success}
        context="User management"
        retry={() => setReload((v) => v + 1)}
      />
      <div className="flex flex-wrap justify-between gap-3 mb-4">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            setPage(1);
            setAppliedSearch(search.trim());
          }}
          className="flex gap-2"
        >
          <input
            className="input"
            placeholder={t("Search name or email")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <button className="px-4 bg-gray-900 text-white rounded-xl">
            {t("Search")}
          </button>
        </form>
        <button
          onClick={() => open()}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> {t("Add User")}
        </button>
      </div>
      {editing && (
        <form
          onSubmit={(e) => void save(e)}
          className="bg-white border rounded-2xl p-6 mb-6"
        >
          <h2 className="text-xl font-bold mb-5">
            {t(editing === "new" ? "Add user" : "Edit user")}
          </h2>
          <div className="grid md:grid-cols-2 gap-4">
            <Field label={t("Name")} error={fieldErrors.name}>
              <input
                className="input"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </Field>
            <Field label={t("Email")} error={fieldErrors.email}>
              <input
                type="email"
                className="input"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </Field>
            <Field label={t("Role")} error={fieldErrors.role}>
              <select
                className="input"
                value={form.role}
                onChange={(e) => setForm({ ...form, role: e.target.value })}
              >
                <option value="">{t("Select role")}</option>
                {roles.map((role) => (
                  <option key={role.id} value={role.id}>
                    {t(role.name)}
                  </option>
                ))}
              </select>
            </Field>
            {editing === "new" && (
              <Field label={t("Branch")} error={fieldErrors.branch_id}>
                <select
                  className="input"
                  value={form.branch_id}
                  onChange={(e) =>
                    setForm({ ...form, branch_id: e.target.value })
                  }
                >
                  <option value="">{t("No branch")}</option>
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.name}
                    </option>
                  ))}
                </select>
              </Field>
            )}
          </div>
          <div className="flex gap-3 mt-5">
            <button
              type="button"
              onClick={() => setEditing(null)}
              className="px-5 py-2.5 bg-gray-100 rounded-xl"
            >
              {t("Cancel")}
            </button>
            <button
              disabled={saving}
              className="px-5 py-2.5 bg-teal-600 text-white rounded-xl disabled:opacity-50"
            >
              {t(saving ? "Saving…" : "Save User")}
            </button>
          </div>
        </form>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <State text={t("Loading users and roles…")} />
        ) : rows.length === 0 ? (
          <State text={t("No users match this search.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("User")}</th>
                <th>{t("Role")}</th>
                <th>{t("Password state")}</th>
                <th>{t("Created")}</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="p-4">
                    <p className="font-semibold">{row.name}</p>
                    <p className="text-sm text-gray-500">{row.email}</p>
                  </td>
                  <td>{row.role?.name ?? t("No role")}</td>
                  <td>
                    {row.requires_password_change ? (
                      <span className="text-amber-700">{t("Change required")}</span>
                    ) : (
                      <span className="text-emerald-700">{t("Current")}</span>
                    )}
                  </td>
                  <td>{formatDateOnly(row.created_at)}</td>
                  <td>
                    <button
                      onClick={() => open(row)}
                      className="p-2 text-teal-600"
                    >
                      <Pencil />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}

export function BranchesSection() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<BranchDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [saving, setSaving] = useState(false),
    [editing, setEditing] = useState<BranchDto | "new" | null>(null);
  const [form, setForm] = useState<BranchPayload>({
      name: "",
      code: "",
      address: null,
      phone: null,
      is_active: true,
    }),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    branchesApi
      .list(page, 15, controller.signal)
      .then((response) => {
        setRows(response.data);
        setLastPage(response.last_page);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(value instanceof ApiClientError ? value : unexpected());
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, reload]);
  function open(branch?: BranchDto) {
    setEditing(branch ?? "new");
    setForm(
      branch
        ? {
            name: branch.name,
            code: branch.code,
            address: branch.address,
            phone: branch.phone,
            is_active: branch.is_active,
          }
        : { name: "", code: "", address: null, phone: null, is_active: true },
    );
    setFieldErrors({});
  }
  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      if (editing === "new") await branchesApi.create(form);
      else if (editing) await branchesApi.update(editing.id, form);
      setSuccess(t(editing === "new" ? "Branch created." : "Branch updated."));
      setEditing(null);
      setReload((v) => v + 1);
    } catch (value) {
      const requestError =
        value instanceof ApiClientError ? value : unexpected();
      setError(requestError);
      setFieldErrors(firstErrors(requestError));
    } finally {
      setSaving(false);
    }
  }
  return (
    <div>
      <Feedback
        error={error}
        success={success}
        context="Branch"
        retry={() => setReload((v) => v + 1)}
      />
      <div className="flex justify-end mb-4">
        <button
          onClick={() => open()}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> {t("Add Branch")}
        </button>
      </div>
      {editing && (
        <form
          onSubmit={(e) => void save(e)}
          className="bg-white border rounded-2xl p-6 mb-6 grid md:grid-cols-2 gap-4"
        >
          <Field label={t("Name")} error={fieldErrors.name}>
            <input
              required
              className="input"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </Field>
          <Field label={t("Code")} error={fieldErrors.code}>
            <input
              required
              className="input"
              value={form.code}
              onChange={(e) => setForm({ ...form, code: e.target.value })}
            />
          </Field>
          <Field label={t("Address")} error={fieldErrors.address}>
            <input
              className="input"
              value={form.address ?? ""}
              onChange={(e) =>
                setForm({ ...form, address: e.target.value || null })
              }
            />
          </Field>
          <Field label={t("Phone")} error={fieldErrors.phone}>
            <input
              className="input"
              value={form.phone ?? ""}
              onChange={(e) =>
                setForm({ ...form, phone: e.target.value || null })
              }
            />
          </Field>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) =>
                setForm({ ...form, is_active: e.target.checked })
              }
            />{" "}
            {t("Active")}
          </label>
          <button
            disabled={saving}
            className="px-5 py-2.5 bg-teal-600 text-white rounded-xl disabled:opacity-50"
          >
            {t(saving ? "Saving…" : "Save Branch")}
          </button>
        </form>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <State text={t("Loading branches…")} />
        ) : rows.length === 0 ? (
          <State text={t("No branches found.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("Code")}</th>
                <th>{t("Name")}</th>
                <th>{t("Address")}</th>
                <th>{t("Status")}</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="p-4 font-mono">{row.code}</td>
                  <td>{row.name}</td>
                  <td>{row.address ?? "—"}</td>
                  <td>{t(row.is_active ? "Active" : "Inactive")}</td>
                  <td>
                    <button
                      onClick={() => open(row)}
                      className="p-2 text-teal-600"
                    >
                      <Pencil />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}

function Feedback({
  error,
  success,
  context,
  retry,
}: {
  error: ApiClientError | null;
  success: string;
  context: string;
  retry: () => void;
}) {
  const { t } = useTranslation();
  const contextLabel = t(context);
  return (
    <>
      {success && (
        <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      {error && (
        <div className="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl">
          <strong>
            {error.status === 403
              ? t("contextAccessDenied", { context: contextLabel })
              : error.status === 404
                ? t("contextNotFound", { context: contextLabel })
                : error.status === 409
                  ? t("contextConflict", { context: contextLabel })
                  : error.status === 422
                    ? t("contextValidationFailed", { context: contextLabel })
                    : error.status === 0
                      ? t("Connection failure")
                      : t("Unexpected technical failure")}
          </strong>
          <p>{error.message}</p>
          {error.status === 422 &&
            Object.entries(error.errors).map(([field, messages]) => (
              <p key={field} className="text-sm">
                {field}: {messages.join(" ")}
              </p>
            ))}
          {error.status !== 403 && (
            <button onClick={retry} className="mt-2 underline">
              {t("Retry")}
            </button>
          )}
        </div>
      )}
    </>
  );
}
function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label>
      <span className="block text-sm font-medium mb-2">{label}</span>
      {children}
      {error && (
        <span className="block text-xs text-red-600 mt-1">{error}</span>
      )}
    </label>
  );
}
function Pager({
  page,
  lastPage,
  setPage,
}: {
  page: number;
  lastPage: number;
  setPage: (page: number) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="flex justify-end gap-3 mt-4">
      <button
        disabled={page <= 1}
        onClick={() => setPage(page - 1)}
        className="px-4 py-2 border rounded-xl disabled:opacity-40"
      >
        {t("Previous")}
      </button>
      <span className="py-2">
        <bdi dir="ltr">{page} / {lastPage}</bdi>
      </span>
      <button
        disabled={page >= lastPage}
        onClick={() => setPage(page + 1)}
        className="px-4 py-2 border rounded-xl disabled:opacity-40"
      >
        {t("Next")}
      </button>
    </div>
  );
}
function Status({ value }: { value: AccountingPeriodDto["status"] }) {
  const { t } = useTranslation();
  return (
    <span
      className={`px-3 py-1 rounded-full text-sm ${value === "open" ? "bg-emerald-100 text-emerald-700" : value === "closed" ? "bg-gray-100 text-gray-700" : "bg-amber-100 text-amber-700"}`}
    >
      {t(value)}
    </span>
  );
}
function State({ text }: { text: string }) {
  return <div className="p-12 text-center text-gray-500">{text}</div>;
}
function firstErrors(error: ApiClientError): Record<string, string> {
  return Object.fromEntries(
    Object.entries(error.errors).map(([key, messages]) => [key, messages[0]]),
  );
}
async function loadBranches(signal: AbortSignal): Promise<BranchDto[]> {
  const rows: BranchDto[] = [];
  for (let page = 1; ; page += 1) {
    const response = await branchesApi.list(page, 100, signal);
    rows.push(...response.data);
    if (page >= response.last_page) return rows;
  }
}
