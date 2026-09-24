import { useEffect, useState } from "react";
import { Archive, Search } from "lucide-react";
import { ApiClientError } from "../../api/client";
import { batchesApi } from "../../api/batches";
import { PermissionGate } from "../../auth/PermissionGate";
import { useTranslation } from "react-i18next";
import i18n from "../../i18n";
import type { BatchDto } from "../../types/batch";
import {
  batchStatus,
  formatDateOnly,
  formatMoney,
} from "../../utils/batchFormatters";

export function BatchesPage() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<BatchDto[]>([]),
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
    [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    batchesApi
      .list(
        {
          page,
          per_page: 15,
          search,
          sort: "expiry_date",
          direction: "asc",
          archive_status: archiveStatus,
        },
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
              : new ApiClientError(0, t("Unable to load batches.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [archiveStatus, page, reload, search]);
  async function archive(row: BatchDto) {
    if (!window.confirm(t("archiveBatchQuestion", { batch: row.batch_number })))
      return;
    setBusy(row.id);
    setError(null);
    try {
      await batchesApi.delete(row.id);
      setSuccess(t("batchArchived", { batch: row.batch_number }));
      setReload((v) => v + 1);
    } catch (value) {
      setError(normalize(value));
    } finally {
      setBusy(null);
    }
  }
  async function restore(row: BatchDto) {
    if (!window.confirm(t("restoreBatchQuestion", { batch: row.batch_number })))
      return;
    setBusy(row.id);
    setError(null);
    try {
      await batchesApi.restore(row.id);
      setSuccess(t("batchRestored", { batch: row.batch_number }));
      setReload((v) => v + 1);
    } catch (value) {
      setError(normalize(value));
    } finally {
      setBusy(null);
    }
  }
  return (
    <div className="p-4 sm:p-8">
      <h1 className="text-3xl font-bold">{t("Batch Management")}</h1>
      <p className="text-gray-500 mb-8">
        {t("Persisted stock, cost, price, expiry, and archive state")}
      </p>
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
            placeholder={t("Search batch number")}
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
        <ErrorBox error={error} retry={() => setReload((v) => v + 1)} />
      )}{" "}
      {loading ? (
        <State text={t("Loading batches…")} />
      ) : !error && rows.length === 0 ? (
        <State text={t("No batches found in this view.")} />
      ) : (
        !error && (
          <div className="bg-white border rounded-2xl overflow-x-auto">
            <table className="w-full min-w-[940px]">
              <thead className="bg-gray-50 text-start">
                <tr>
                  <th className="p-4">{t("Product")}</th>
                  <th>{t("Batch")}</th>
                  <th>{t("Expiry")}</th>
                  <th className="text-end">{t("Quantity")}</th>
                  <th className="text-end">{t("Purchase cost")}</th>
                  <th className="text-end">{t("Selling price")}</th>
                  <th>{t("Status")}</th>
                  <th className="p-4 text-end">{t("Action")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="p-4 font-semibold">
                      <bdi>
                        {row.product?.name ??
                          t("productReference", { id: row.product_id })}
                      </bdi>
                    </td>
                    <td className="font-mono">
                      <bdi dir="ltr">{row.batch_number}</bdi>
                    </td>
                    <td>{formatDateOnly(row.expiry_date)}</td>
                    <td className="text-end">{row.quantity}</td>
                    <td className="text-end">
                      {formatMoney(row.purchase_price)}
                    </td>
                    <td className="text-end">{formatMoney(row.sale_price)}</td>
                    <td>
                      {row.deleted_at ? (
                        <span className="badge">{t("Archived")}</span>
                      ) : (
                        <StatusBadge status={batchStatus(row)} />
                      )}
                    </td>
                    <td className="p-4 text-end">
                      <PermissionGate permission="batches.manage">
                        {row.deleted_at ? (
                          <button
                            disabled={busy === row.id}
                            onClick={() => void restore(row)}
                            className="px-3 py-2 bg-emerald-50 text-emerald-700 rounded-lg disabled:opacity-50"
                          >
                            {t(busy === row.id ? "Restoring…" : "Restore")}
                          </button>
                        ) : (
                          <button
                            disabled={busy === row.id || row.quantity > 0}
                            title={
                              row.quantity > 0
                                ? t("Stock must reach zero before archival")
                                : t("Archive batch")
                            }
                            onClick={() => void archive(row)}
                            className="px-3 py-2 bg-red-50 text-red-700 rounded-lg disabled:opacity-40"
                          >
                            <Archive className="inline w-4 h-4 mr-1" />
                            {t("Archive")}
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
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}
export function StatusBadge({
  status,
}: {
  status: ReturnType<typeof batchStatus>;
}) {
  const { t } = useTranslation();
  return (
    <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
      {t(status.replaceAll("-", " ").replace(/\b\w/g, (c) => c.toUpperCase()))}
    </span>
  );
}
const normalize = (v: unknown) =>
  v instanceof ApiClientError
    ? v
    : new ApiClientError(0, i18n.t("Unable to complete the batch request."));
function State({ text }: { text: string }) {
  return (
    <div className="p-12 bg-white border rounded-2xl text-center text-gray-500">
      {text}
    </div>
  );
}
function ErrorBox({
  error,
  retry,
}: {
  error: ApiClientError;
  retry: () => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="p-5 mb-5 bg-red-50 border text-red-700 rounded-xl">
      <strong>
        {error.status === 403
          ? t("Batch access denied")
          : error.status === 409
            ? t("Batch archive/restore conflict")
            : t("Batch request failed")}
      </strong>
      <p>{error.message}</p>
      {error.status !== 403 && (
        <button onClick={retry} className="underline mt-2">
          {t("Retry")}
        </button>
      )}
    </div>
  );
}
function Pager({
  page,
  lastPage,
  setPage,
}: {
  page: number;
  lastPage: number;
  setPage: (n: number) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="flex justify-end gap-3 mt-5">
      <button
        disabled={page <= 1}
        onClick={() => setPage(page - 1)}
        className="px-4 py-2 border rounded-xl disabled:opacity-40"
      >
        {t("Previous")}
      </button>
      <span className="py-2">{t("pageOf", { page, lastPage })}</span>
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
