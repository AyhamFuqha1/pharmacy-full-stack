import { useEffect, useState } from "react";
import {
  ArrowLeft,
  Building2,
  Edit,
  FileText,
  MapPin,
  Phone,
  Trash2,
  User,
} from "lucide-react";
import { Link, useLocation, useNavigate, useParams } from "react-router";
import { useTranslation } from "react-i18next";
import { customersApi } from "../../api/customers";
import { providersApi } from "../../api/providers";
import { statementsApi } from "../../api/statements";
import { ApiClientError } from "../../api/client";
import { PermissionGate } from "../../auth/PermissionGate";
import type {
  CustomerDto,
  PartyPayload,
  PartyStatementFilters,
  PartyStatementDto,
  ProviderDto,
} from "../../types/party";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import { PartyFormModal } from "./PartyFormModal";
import { human, PrintReportButton } from "./ErpPrimitives";
import { RemoteEntitySelect } from "./UxPrimitives";
import { userEntityLoader } from "../entityLoaders";

type Kind = "customer" | "supplier";
type Party = CustomerDto | ProviderDto;

export function PartyDetails({ kind }: { kind: Kind }) {
  const { t } = useTranslation();
  const { id } = useParams();
  const partyId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const [party, setParty] = useState<Party | null>(null);
  const [statement, setStatement] = useState<PartyStatementDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [statementError, setStatementError] = useState<ApiClientError | null>(
    null,
  );
  const emptyStatementFilters: PartyStatementFilters = { per_page: 50 };
  const [statementDraft, setStatementDraft] = useState<PartyStatementFilters>(emptyStatementFilters);
  const [statementFilters, setStatementFilters] = useState<PartyStatementFilters>(emptyStatementFilters);
  const [statementLoading, setStatementLoading] = useState(false);
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [success, setSuccess] = useState("");
  const label = kind === "customer" ? "Customer" : "Supplier";
  const basePath = kind === "customer" ? "/customers" : "/suppliers";

  useEffect(() => {
    if (!Number.isInteger(partyId) || partyId <= 0) {
      setError(
        new ApiClientError(404, t("partyNotFoundMessage", { party: t(label) })),
      );
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const archived =
      new URLSearchParams(location.search).get("archive_status") === "archived"
        ? ("archived" as const)
        : undefined;
    const detailRequest =
      kind === "customer"
        ? customersApi.detail(partyId, controller.signal, archived)
        : providersApi.detail(partyId, controller.signal, archived);
    detailRequest
      .then((response) => setParty(response.data))
      .catch((value: unknown) => {
        if (value instanceof DOMException && value.name === "AbortError")
          return;
        setError(
          value instanceof ApiClientError
            ? value
            : new ApiClientError(
                0,
                t("unableToLoadParty", { party: t(label) }),
              ),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [kind, label, location.search, partyId, reload, t]);

  useEffect(() => {
    if (!Number.isInteger(partyId) || partyId <= 0) return;
    const controller = new AbortController();
    setStatementLoading(true);
    setStatementError(null);
    const request = kind === "customer"
      ? statementsApi.customer(partyId, statementFilters, controller.signal)
      : statementsApi.supplier(partyId, statementFilters, controller.signal);
    request.then(setStatement).catch((value: unknown) => {
      if (value instanceof DOMException && value.name === "AbortError") return;
      setStatementError(value instanceof ApiClientError ? value : new ApiClientError(0, t("Unable to load accounting statement.")));
    }).finally(() => { if (!controller.signal.aborted) setStatementLoading(false); });
    return () => controller.abort();
  }, [kind, partyId, statementFilters, reload, t]);

  async function update(payload: PartyPayload) {
    const response =
      kind === "customer"
        ? await customersApi.update(partyId, payload)
        : await providersApi.update(partyId, payload);
    setParty(response.data);
    setEditing(false);
    setSuccess(t("partyUpdatedSuccessfully", { party: t(label) }));
  }
  async function remove() {
    setDeleting(true);
    try {
      if (kind === "customer") await customersApi.delete(partyId);
      else await providersApi.delete(partyId);
      navigate(basePath, {
        replace: true,
        state: { success: t("partyDeleted", { party: t(label) }) },
      });
    } catch (value) {
      setConfirmDelete(false);
      setError(
        value instanceof ApiClientError
          ? value
          : new ApiClientError(
              0,
              t("unableToDeleteParty", { party: t(label) }),
            ),
      );
    } finally {
      setDeleting(false);
    }
  }

  if (loading)
    return (
      <div className="p-8">
        <div className="bg-white rounded-2xl border p-12 text-center text-gray-500">
          {t("loadingParty", { party: t(label) })}
        </div>
      </div>
    );
  if (error || !party)
    return (
      <div className="p-8">
        <button onClick={() => navigate(basePath)} className="flex gap-2 mb-6">
          <ArrowLeft className="rtl:rotate-180" />{" "}
          {t("backToParties", { parties: t(`${label}s`) })}
        </button>
        <div className="bg-red-50 border border-red-200 rounded-2xl p-12 text-center">
          <h1 className="text-2xl font-bold">
            {error?.status === 404
              ? t("partyNotFound", { party: t(label) })
              : error?.status === 403
                ? t("partyAccessDenied", { party: t(label) })
                : t("unableToLoadParty", { party: t(label) })}
          </h1>
          <p className="text-red-700 my-4">{error?.message}</p>
          {error?.status !== 403 && error?.status !== 404 && (
            <button
              onClick={() => setReload((key) => key + 1)}
              className="px-4 py-2 bg-red-600 text-white rounded-xl"
            >
              {t("Retry")}
            </button>
          )}
        </div>
      </div>
    );

  const Icon = kind === "customer" ? User : Building2;
  return (
    <div className="p-8">
      <button
        onClick={() => navigate(basePath)}
        className="flex items-center gap-2 text-gray-600 mb-6"
      >
        <ArrowLeft className="w-5 h-5 rtl:rotate-180" />{" "}
        {t("backToParties", { parties: t(`${label}s`) })}
      </button>
      {success && (
        <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      <div className="bg-white rounded-2xl border p-8 mb-6">
        <div className="flex flex-col lg:flex-row justify-between gap-8">
          <div className="flex gap-4">
            <div className="w-20 h-20 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center">
              <Icon className="w-10 h-10 text-white" />
            </div>
            <div>
              <h1 className="text-3xl font-bold mb-3">
                <bdi>{party.name}</bdi>
              </h1>
              <p className="flex gap-2 text-gray-600 mb-2">
                <Phone className="w-4 h-4" />{" "}
                <bdi dir="ltr">
                  {party.phone_num || t("Phone not provided")}
                </bdi>
              </p>
              <p className="flex gap-2 text-gray-600">
                <MapPin className="w-4 h-4" />{" "}
                <bdi>{party.city || t("City not provided")}</bdi>
              </p>
            </div>
          </div>
          <div className="min-w-64 p-5 bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-200 rounded-xl">
            <p className="text-sm font-semibold text-teal-700">
              {t("Accounting balance")}
            </p>
            <p className="text-3xl font-bold text-teal-800 mt-2">
              {statement ? formatMoney(statement.balance) : t("Unavailable")}
            </p>
            <p className="text-xs text-teal-700 mt-2">
              {t("Authoritative posted-ledger balance")}
            </p>
          </div>
        </div>
        <PermissionGate permission="accounting.vouchers">
          <div className="flex gap-3 mt-6">
            <button
              onClick={() => setEditing(true)}
              className="flex gap-2 px-4 py-2.5 bg-gray-100 rounded-xl"
            >
              <Edit className="w-4 h-4" /> {t("editParty", { party: t(label) })}
            </button>
            <button
              onClick={() => setConfirmDelete(true)}
              className="flex gap-2 px-4 py-2.5 bg-red-50 text-red-600 rounded-xl"
            >
              <Trash2 className="w-4 h-4" /> {t("Delete")}
            </button>
          </div>
        </PermissionGate>
      </div>
      <div className="bg-white rounded-2xl border overflow-hidden mb-6">
        <div className="p-6 border-b">
          <h2 className="text-xl font-bold" id="statement">
            {t("Accounting statement")}
          </h2>
          <p className="text-sm text-gray-500">{t("statementHelp")}</p>
          <p className="text-sm text-gray-600 mt-1"><bdi>{party.name}</bdi> · {statementFilters.from || t("Beginning")} — {statementFilters.to || t("Today")}</p>
        </div>
        <form className="grid gap-3 p-4 border-b bg-gray-50 sm:grid-cols-2 lg:grid-cols-6" onSubmit={(event) => { event.preventDefault(); setStatementFilters({ ...statementDraft, page: 1, per_page: 50 }); }}>
          <label className="text-sm"><span className="block mb-1">{t("From Date")}</span><input className="input" type="date" value={statementDraft.from || ""} onChange={(event) => setStatementDraft((value) => ({ ...value, from: event.target.value }))} /></label>
          <label className="text-sm"><span className="block mb-1">{t("To Date")}</span><input className="input" type="date" value={statementDraft.to || ""} onChange={(event) => setStatementDraft((value) => ({ ...value, to: event.target.value }))} /></label>
          <label className="text-sm"><span className="block mb-1">{t("Transaction Type")}</span><select className="input" value={statementDraft.transaction_type || ""} onChange={(event) => setStatementDraft((value) => ({ ...value, transaction_type: event.target.value }))}><option value="">{t("All types")}</option>{(kind === "customer" ? ["sales_invoice", "sales_invoice_reversal", "sales_return", "receipt", "cheque_clear", "cheque_bounce"] : ["purchase_invoice", "purchase_return", "payment", "cheque_clear", "cheque_bounce"]).map((value) => <option key={value} value={value}>{t(human(value))}</option>)}</select></label>
          <label className="text-sm"><span className="block mb-1">{t("Reference")}</span><input className="input" value={statementDraft.reference || ""} onChange={(event) => setStatementDraft((value) => ({ ...value, reference: event.target.value }))} /></label>
          <label className="text-sm"><span className="block mb-1">{t("Search")}</span><input className="input" value={statementDraft.search || ""} onChange={(event) => setStatementDraft((value) => ({ ...value, search: event.target.value }))} /></label>
          <RemoteEntitySelect label={t("User")} value={String(statementDraft.user_id || "")} onChange={(value) => setStatementDraft((draft) => ({ ...draft, user_id: value ? Number(value) : undefined }))} load={userEntityLoader} placeholder={t("Search team member name or email")} />
          <div className="flex items-end gap-2 lg:col-span-6"><button className="report-action primary" type="submit">{t("Apply")}</button><button className="report-action" type="button" onClick={() => { setStatementDraft(emptyStatementFilters); setStatementFilters(emptyStatementFilters); }}>{t("Clear")}</button><PrintReportButton type={kind === "customer" ? "customer_statement" : "supplier_statement"} filters={{ ...statementFilters, [kind === "customer" ? "customer_id" : "provider_id"]: partyId }} label={t("Print Statement")} /></div>
        </form>
        {statement && <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 p-4 border-b"><StatementMetric label={t("Opening Balance")} value={statement.opening_balance} /><StatementMetric label={t("Total Debit")} value={statement.total_debit} /><StatementMetric label={t("Total Credit")} value={statement.total_credit} /><StatementMetric label={t("Net Movement")} value={statement.period_change} /><StatementMetric label={t("Closing Balance")} value={statement.closing_balance} /></div>}
        {statementError ? (
          <div className="p-8 text-center text-red-700">
            {statementError.message}
          </div>
        ) : statementLoading && !statement ? (
          <div className="p-8 text-center text-gray-500">
            {t("Loading statement…")}
          </div>
        ) : statement ? (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[980px]">
              <thead className="bg-gray-50 text-start text-xs uppercase text-gray-600">
                <tr>
                  <th className="p-4">{t("Date")}</th>
                  <th className="p-4">{t("Type")}</th>
                  <th className="p-4">{t("Reference")}</th>
                  <th className="p-4">{t("Description")}</th>
                  <th className="p-4 text-end">{t("Debit")}</th>
                  <th className="p-4 text-end">{t("Credit")}</th>
                  <th className="p-4 text-end">{t("Running Balance")}</th>
                  <th className="p-4">{t("User")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                <tr className="bg-teal-50/60 font-semibold">
                  <td className="p-4">{statementFilters.from ? formatDateOnly(statementFilters.from) : "—"}</td>
                  <td className="p-4">{t("Opening Balance")}</td>
                  <td className="p-4">—</td>
                  <td className="p-4">{t("Opening balance for the period")}</td>
                  <td className="p-4 text-end">—</td>
                  <td className="p-4 text-end">—</td>
                  <td className="p-4 text-end">{formatMoney(statement.opening_balance)}</td>
                  <td className="p-4">—</td>
                </tr>
                {statement.lines.map((line) => {
                  const path = sourcePath(line.source_type, line.source_id);
                  const source = line.source_label || t("sourceReference", { type: t(human(line.source_type)), id: line.source_id });
                  return (
                    <tr key={line.id}>
                      <td className="p-4">{formatDateOnly(line.entry_date)}</td>
                      <td className="p-4"><bdi>{t(human(line.source_type))}</bdi></td>
                      <td className="p-4 text-sm">
                        {path ? (
                          <Link
                            to={path}
                            className="text-teal-700 font-semibold"
                          >
                            <bdi>{source}</bdi>
                          </Link>
                        ) : (
                          <bdi>{source}</bdi>
                        )}
                      </td>
                      <td className="p-4">{line.description || line.entry_description || t("No description")}</td>
                      <td className="p-4 text-end">
                        {line.side === "debit" ? formatMoney(line.amount) : "—"}
                      </td>
                      <td className="p-4 text-end">
                        {line.side === "credit"
                          ? formatMoney(line.amount)
                          : "—"}
                      </td>
                      <td className="p-4 text-end font-semibold">{formatMoney(line.running_balance)}</td>
                      <td className="p-4"><bdi>{line.created_by_name || t("System")}</bdi></td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot className="bg-gray-50 font-bold">
                <tr>
                  <td colSpan={4} className="p-4 text-end">
                    {t("Totals")}
                  </td>
                  <td className="p-4 text-end">
                    {formatMoney(statement.total_debit)}
                  </td>
                  <td className="p-4 text-end">
                    {formatMoney(statement.total_credit)}
                  </td>
                  <td className="p-4 text-end">{formatMoney(statement.closing_balance)}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
            {statement.lines.length === 0 && <div className="p-8 text-center text-gray-500">{t("noStatementEntries", { party: t(label) })}</div>}
            <div className="flex items-center justify-between gap-3 p-4 border-t text-sm"><span>{t("Showing {{from}}–{{to}} of {{total}}", { from: statement.meta.from ?? 0, to: statement.meta.to ?? 0, total: statement.meta.total })} · {t("Page {{current}} of {{last}}", { current: statement.meta.current_page, last: statement.meta.last_page })}</span><div className="flex gap-2"><button type="button" className="report-action" disabled={statement.meta.current_page <= 1 || statementLoading} onClick={() => setStatementFilters((value) => ({ ...value, page: statement.meta.current_page - 1 }))}>{t("Previous")}</button><button type="button" className="report-action" disabled={statement.meta.current_page >= statement.meta.last_page || statementLoading} onClick={() => setStatementFilters((value) => ({ ...value, page: statement.meta.current_page + 1 }))}>{t("Next")}</button></div></div>
          </div>
        ) : null}
      </div>
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {kind === "customer" ? (
          <>
            <HistoryLink
              to={`/sales?search=${encodeURIComponent(party.name)}`}
              label={t("Sales invoices")}
            />
            <HistoryLink to="/returns/sales" label={t("Sales returns")} />
            <HistoryLink to="/payments" label={t("Receipts")} />
          </>
        ) : (
          <>
            <HistoryLink to="/purchases" label={t("Purchases")} />
            <HistoryLink
              to="/returns/purchases"
              label={t("Purchase returns")}
            />
            <HistoryLink to="/payment-vouchers" label={t("Payment vouchers")} />
          </>
        )}
        <a
          href="#statement"
          className="p-4 bg-white border rounded-xl text-teal-700 font-semibold"
        >
          {t("Accounting Statement")}
        </a>
      </div>
      {editing && (
        <PartyFormModal
          kind={label}
          initial={{
            name: party.name,
            phone_num: party.phone_num ?? "",
            city: party.city,
            ...(kind === "customer"
              ? {
                  credit_limit:
                    (party as CustomerDto).credit_limit == null
                      ? null
                      : Number((party as CustomerDto).credit_limit),
                  credit_warning_threshold:
                    (party as CustomerDto).credit_warning_threshold == null
                      ? null
                      : Number((party as CustomerDto).credit_warning_threshold),
                  block_on_limit: (party as CustomerDto).block_on_limit,
                }
              : {}),
          }}
          onClose={() => setEditing(false)}
          onSubmit={update}
        />
      )}
      {confirmDelete && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6">
            <h2 className="text-xl font-bold">
              {t("deletePartyQuestion", { party: t(label) })}
            </h2>
            <p className="text-gray-600 my-4">{t("partyDeleteHelp")}</p>
            <div className="flex gap-3">
              <button
                disabled={deleting}
                onClick={() => setConfirmDelete(false)}
                className="flex-1 py-2.5 bg-gray-100 rounded-xl"
              >
                {t("Cancel")}
              </button>
              <button
                disabled={deleting}
                onClick={() => void remove()}
                className="flex-1 py-2.5 bg-red-600 text-white rounded-xl"
              >
                {deleting ? t("Deleting…") : t("Delete")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function StatementMetric({ label, value }: { label: string; value: string | number }) {
  return <div className="rounded-xl border bg-white p-3"><div className="text-xs text-gray-500">{label}</div><div className="mt-1 font-bold text-gray-900">{formatMoney(value)}</div></div>;
}

function sourcePath(type: string, id: number): string | null {
  const paths: Record<string, string> = {
    sales_invoice: `/sales/${id}`,
    sales_return: `/returns/sales/${id}`,
    purchase_invoice: `/purchases/${id}`,
    purchase_return: `/returns/purchases/${id}`,
    receipt: `/receipt/${id}`,
    receipt_voucher: `/receipt/${id}`,
  };
  return paths[type] ?? null;
}
function HistoryLink({ to, label }: { to: string; label: string }) {
  return (
    <Link
      to={to}
      className="p-4 bg-white border rounded-xl text-teal-700 font-semibold"
    >
      {label}
    </Link>
  );
}
