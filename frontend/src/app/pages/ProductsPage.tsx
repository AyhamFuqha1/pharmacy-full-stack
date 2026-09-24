import { useEffect, useState } from "react";
import { Edit, Eye, Plus, Search, Trash2 } from "lucide-react";
import { useLocation, useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import { ApiClientError } from "../../api/client";
import { productsApi } from "../../api/products";
import { PermissionGate } from "../../auth/PermissionGate";
import type { ProductDto } from "../../types/product";

export function ProductsPage() {
  const { t } = useTranslation(),
    navigate = useNavigate(),
    location = useLocation();
  const [rows, setRows] = useState<ProductDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [searchInput, setSearchInput] = useState(""),
    [search, setSearch] = useState(""),
    [archiveStatus, setArchiveStatus] = useState<"active" | "archived" | "all">(
      "active",
    ),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState<number | null>(null),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0),
    [success, setSuccess] = useState(
      (location.state as { success?: string } | null)?.success ?? "",
    );
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    productsApi
      .list(
        {
          page,
          per_page: 15,
          search,
          sort: "name",
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
              : new ApiClientError(0, t("Unable to load products.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [archiveStatus, page, reload, search, t]);
  async function archive(row: ProductDto) {
    if (!window.confirm(t("archiveProductQuestion", { name: row.name })))
      return;
    setBusy(row.id);
    setError(null);
    try {
      await productsApi.delete(row.id);
      setSuccess(t("productArchived", { name: row.name }));
      setReload((v) => v + 1);
    } catch (value) {
      setError(normalize(value, t));
    } finally {
      setBusy(null);
    }
  }
  async function restore(row: ProductDto) {
    if (!window.confirm(t("restoreProductQuestion", { name: row.name })))
      return;
    setBusy(row.id);
    setError(null);
    try {
      await productsApi.restore(row.id);
      setSuccess(t("productRestored", { name: row.name }));
      setReload((v) => v + 1);
    } catch (value) {
      setError(normalize(value, t));
    } finally {
      setBusy(null);
    }
  }
  return (
    <div className="p-4 sm:p-8">
      <div className="flex flex-wrap justify-between gap-4 mb-8">
        <div>
          <h1 className="text-3xl font-bold">{t("Products Management")}</h1>
          <p className="text-gray-500">
            {t("Persisted product master records")}
          </p>
        </div>
        <PermissionGate permission="products.manage">
          <button
            onClick={() => navigate("/products/add")}
            className="flex gap-2 px-4 py-2.5 bg-teal-600 text-white rounded-xl"
          >
            <Plus /> {t("Add Product")}
          </button>
        </PermissionGate>
      </div>
      {success && (
        <div className="mb-5 p-4 bg-emerald-50 border text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(searchInput.trim());
          setPage(1);
        }}
        className="bg-white border rounded-2xl p-5 mb-6 flex flex-wrap gap-3"
      >
        <label className="relative flex-1 min-w-60">
          <Search className="absolute start-3 top-3 w-5 h-5 text-gray-400" />
          <input
            className="input ps-10"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder={t("Search name or barcode")}
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
      )}
      {loading ? (
        <State text={t("Loading products…")} />
      ) : !error && rows.length === 0 ? (
        <State text={t("No products found in this view.")} />
      ) : (
        !error && (
          <div className="bg-white border rounded-2xl overflow-x-auto">
            <table className="w-full min-w-[760px]">
              <thead className="bg-gray-50 text-start">
                <tr>
                  <th className="p-4">{t("Product")}</th>
                  <th>{t("Barcode")}</th>
                  <th>{t("Type")}</th>
                  <th>{t("Status")}</th>
                  <th className="text-end p-4">{t("Actions")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="p-4 font-semibold">
                      <bdi>{row.name}</bdi>
                      <div className="text-xs text-gray-500">
                        <bdi>{row.nots || t("No notes")}</bdi>
                      </div>
                    </td>
                    <td className="font-mono">
                      <bdi dir="ltr">{row.barcode}</bdi>
                    </td>
                    <td>
                      {row.is_controlled ? (
                        <>
                          {t("Controlled")}
                          {row.control_schedule && (
                            <>
                              {" "}
                              · <bdi>{row.control_schedule}</bdi>
                            </>
                          )}
                        </>
                      ) : (
                        t("Standard")
                      )}
                    </td>
                    <td>
                      <Badge archived={Boolean(row.deleted_at)} />
                    </td>
                    <td className="p-4">
                      <div className="flex justify-end gap-2">
                        <button
                          title={t("View")}
                          onClick={() =>
                            navigate(
                              `/products/${row.id}${row.deleted_at ? "?archive_status=archived" : ""}`,
                            )
                          }
                          className="p-2 text-teal-700"
                        >
                          <Eye />
                        </button>
                        <PermissionGate permission="products.manage">
                          {row.deleted_at ? (
                            <button
                              disabled={busy === row.id}
                              onClick={() => void restore(row)}
                              className="px-3 py-2 bg-emerald-50 text-emerald-700 rounded-lg disabled:opacity-50"
                            >
                              {busy === row.id ? t("Restoring…") : t("Restore")}
                            </button>
                          ) : (
                            <>
                              <button
                                title={t("Edit")}
                                onClick={() =>
                                  navigate(`/products/${row.id}/edit`)
                                }
                                className="p-2"
                              >
                                <Edit />
                              </button>
                              <button
                                title={t("Delete")}
                                disabled={busy === row.id}
                                onClick={() => void archive(row)}
                                className="p-2 text-red-600"
                              >
                                <Trash2 />
                              </button>
                            </>
                          )}
                        </PermissionGate>
                      </div>
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
function normalize(value: unknown, t: (key: string) => string) {
  return value instanceof ApiClientError
    ? value
    : new ApiClientError(0, t("Unable to complete the product request."));
}
function Badge({ archived }: { archived: boolean }) {
  const { t } = useTranslation();
  return (
    <span
      className={`px-2.5 py-1 rounded-full text-xs font-semibold ${archived ? "bg-gray-100 text-gray-700" : "bg-emerald-50 text-emerald-700"}`}
    >
      {t(archived ? "Archived" : "Active")}
    </span>
  );
}
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
    <div className="mb-5 p-5 bg-red-50 border border-red-200 text-red-700 rounded-xl">
      <strong>
        {error.status === 403
          ? t("Products access denied")
          : error.status === 409
            ? t("Product archive/restore conflict")
            : t("Product request failed")}
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
  setPage: (value: number) => void;
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
