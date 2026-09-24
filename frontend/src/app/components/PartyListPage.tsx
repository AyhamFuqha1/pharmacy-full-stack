import { useEffect, useState } from "react";
import { Plus, Search } from "lucide-react";
import { useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import { ApiClientError } from "../../api/client";
import { customersApi } from "../../api/customers";
import { providersApi } from "../../api/providers";
import { PermissionGate } from "../../auth/PermissionGate";
import type { CustomerDto, PartyPayload, ProviderDto } from "../../types/party";
import { PartyFormModal } from "./PartyFormModal";

export function PartyListPage({ kind }: { kind: "Customer" | "Supplier" }) {
  const { t } = useTranslation();
  const customer = kind === "Customer",
    navigate = useNavigate(),
    api = customer ? customersApi : providersApi;
  const [rows, setRows] = useState<Array<CustomerDto | ProviderDto>>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [input, setInput] = useState(""),
    [search, setSearch] = useState(""),
    [archiveStatus, setArchiveStatus] = useState<"active" | "archived" | "all">(
      "active",
    ),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState<number | null>(null),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [form, setForm] = useState(false),
    [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    api
      .list(
        { page, per_page: 15, search, archive_status: archiveStatus },
        controller.signal,
      )
      .then((r) => {
        setRows(r.data);
        setLastPage(r.meta.last_page ?? 1);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load parties.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [api, archiveStatus, kind, page, reload, search]);
  async function create(payload: PartyPayload) {
    await api.create(payload);
    setForm(false);
    setSuccess(t("partyCreated", { party: t(kind) }));
    setReload((v) => v + 1);
  }
  async function restore(row: CustomerDto | ProviderDto) {
    if (!window.confirm(t("restoreParty", { name: row.name }))) return;
    setBusy(row.id);
    setError(null);
    try {
      await api.restore(row.id);
      setSuccess(t("partyRestored", { party: t(kind) }));
      setReload((v) => v + 1);
    } catch (value) {
      setError(
        value instanceof ApiClientError
          ? value
          : new ApiClientError(0, t("Unable to restore party.")),
      );
    } finally {
      setBusy(null);
    }
  }
  return (
    <div className="p-4 sm:p-8">
      <div className="flex flex-wrap justify-between gap-3 mb-8">
        <div>
          <h1 className="text-3xl font-bold">{t(`${kind}s`)}</h1>
          <p className="text-gray-500">
            {t("partyMasterRecords", { party: t(kind).toLocaleLowerCase() })}
          </p>
        </div>
        <PermissionGate permission="accounting.vouchers">
          <button
            onClick={() => setForm(true)}
            className="flex gap-2 px-4 py-2.5 bg-teal-600 text-white rounded-xl"
          >
            <Plus /> {t("addParty", { party: t(kind) })}
          </button>
        </PermissionGate>
      </div>
      {success && (
        <div className="p-4 mb-5 bg-emerald-50 border text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(input.trim());
          setPage(1);
        }}
        className="bg-white border rounded-2xl p-5 mb-6 flex flex-wrap gap-3"
      >
        <label className="relative flex-1 min-w-60">
          <Search className="absolute start-3 top-3 w-5 h-5 text-gray-400" />
          <input
            className="input ps-10"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder={t("searchParties", { parties: t(`${kind}s`) })}
          />
        </label>
        <select
          className="input sm:w-44"
          value={archiveStatus}
          onChange={(e) => {
            setArchiveStatus(e.target.value as typeof archiveStatus);
            setPage(1);
          }}
        >
          <option value="active">{t("Active")}</option>
          <option value="archived">{t("Archived")}</option>
          <option value="all">{t("All")}</option>
        </select>
        <button className="px-5 bg-teal-600 text-white rounded-xl">
          {t("Search")}
        </button>
      </form>
      {error && (
        <div className="p-5 mb-5 bg-red-50 border text-red-700 rounded-xl">
          <strong>
            {error.status === 403
              ? t("partyAccessDenied", { party: t(kind) })
              : error.status === 409
                ? t("partyRestoreConflict", { party: t(kind) })
                : t("partyRequestFailed", { party: t(kind) })}
          </strong>
          <p>{error.message}</p>
          {error.status !== 403 && (
            <button
              onClick={() => setReload((v) => v + 1)}
              className="underline mt-2"
            >
              {t("Retry")}
            </button>
          )}
        </div>
      )}
      {loading ? (
        <State text={t("loadingParties", { parties: t(`${kind}s`) })} />
      ) : !error && rows.length === 0 ? (
        <State text={t("noPartiesFound", { parties: t(`${kind}s`) })} />
      ) : (
        !error && (
          <div className="bg-white border rounded-2xl overflow-x-auto">
            <table className="w-full min-w-[680px]">
              <thead className="bg-gray-50 text-start">
                <tr>
                  <th className="p-4">{t("Name")}</th>
                  <th>{t("Phone")}</th>
                  <th>{t("City")}</th>
                  <th>{t("Status")}</th>
                  <th className="p-4 text-end">{t("Action")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="p-4">
                      <button
                        onClick={() =>
                          navigate(
                            `/${customer ? "customers" : "suppliers"}/${row.id}${row.deleted_at ? "?archive_status=archived" : ""}`,
                          )
                        }
                        className="font-semibold text-teal-700"
                      >
                        <bdi>{row.name}</bdi>
                      </button>
                    </td>
                    <td>
                      <bdi dir="ltr">{row.phone_num || t("Not provided")}</bdi>
                    </td>
                    <td>
                      <bdi>{row.city || t("Not provided")}</bdi>
                    </td>
                    <td>
                      {row.deleted_at ? (
                        <span className="px-2 py-1 bg-gray-100 rounded-full text-xs">
                          {t("Archived")}
                        </span>
                      ) : (
                        <span className="px-2 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs">
                          {t("Active")}
                        </span>
                      )}
                    </td>
                    <td className="p-4 text-end">
                      <PermissionGate permission="accounting.vouchers">
                        {row.deleted_at && (
                          <button
                            disabled={busy === row.id}
                            onClick={() => void restore(row)}
                            className="px-3 py-2 bg-emerald-50 text-emerald-700 rounded-lg disabled:opacity-50"
                          >
                            {busy === row.id ? t("Restoring…") : t("Restore")}
                          </button>
                        )}
                      </PermissionGate>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}
      <div className="flex justify-end gap-3 mt-5">
        <button
          disabled={page <= 1}
          onClick={() => setPage(page - 1)}
          className="px-4 py-2 border rounded-xl disabled:opacity-40"
        >
          {t("Previous")}
        </button>
        <span className="py-2">
          {page} / {lastPage}
        </span>
        <button
          disabled={page >= lastPage}
          onClick={() => setPage(page + 1)}
          className="px-4 py-2 border rounded-xl disabled:opacity-40"
        >
          {t("Next")}
        </button>
      </div>
      {form && (
        <PartyFormModal
          kind={kind}
          onClose={() => setForm(false)}
          onSubmit={create}
        />
      )}
    </div>
  );
}
function State({ text }: { text: string }) {
  return (
    <div className="p-12 bg-white border rounded-2xl text-center text-gray-500">
      {text}
    </div>
  );
}
