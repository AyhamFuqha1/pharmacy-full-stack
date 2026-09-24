import { useState } from "react";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import {
  AttachmentsSection,
  ErrorNotice,
  PrintDocumentButton,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";
import {
  EntitySelect,
  Field,
  FieldHelp,
  FormSection,
  PageHeader,
  RemoteEntitySelect,
} from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { formatDateOnly } from "../../utils/batchFormatters";
import { productEntityLoader } from "../entityLoaders";
export function CompleteStockCountsPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    [branch, setBranch] = useState(""),
    [scope, setScope] = useState("selected"),
    [product, setProduct] = useState(""),
    [doc, setDoc] = useState<JsonRecord | null>(null),
    [counts, setCounts] = useState<Record<number, string>>({}),
    [error, setError] = useState<ApiClientError | null>(null);
  const count = doc?.count as JsonRecord | undefined,
    lines = (doc?.lines || []) as JsonRecord[];
  async function create(e: React.FormEvent) {
    e.preventDefault();
    try {
      setError(null);
      setDoc(
        (
          await erpApi.countCreate({
            branch_id: branch ? Number(branch) : null,
            scope,
            product_ids: scope === "selected" ? [Number(product)] : undefined,
          })
        ).data,
      );
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  async function post() {
    if (!count?.id || !confirm(t("postStockCountQuestion"))) return;
    try {
      const payload = {
        lines: lines.map((x) => ({
          batch_id: x.batch_id,
          counted_quantity: Number(
            counts[Number(x.batch_id)] ?? x.system_quantity,
          ),
        })),
      };
      const response = await erpApi.countPost(Number(count.id), payload);
      setDoc(
        response.data.count ? response.data : { ...doc, count: response.data },
      );
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Stock Count"
        description="Compare physical stock with system stock and post the differences."
      />
      <ErrorNotice error={error} />
      {!count ? (
        <form className="space-y-5" onSubmit={create}>
          <FormSection
            title="Count setup"
            description="Choose where and what you are counting. The system records the expected quantities when the draft is created."
          >
            <EntitySelect
              label="Branch"
              value={branch}
              onChange={setBranch}
              options={branches}
              allLabel="All accessible branches"
              help="The pharmacy location where the physical count takes place."
            />
            <Field
              label="Count Scope"
              required
              help="A full count includes every product. A selected count includes one chosen product."
            >
              <select
                className="input"
                value={scope}
                onChange={(e) => setScope(e.target.value)}
              >
                <option value="selected">{t("One product")}</option>
                <option value="full">{t("All products")}</option>
              </select>
            </Field>
            {scope === "selected" && (
              <RemoteEntitySelect
                required
                label="Product"
                value={product}
                onChange={setProduct}
                load={productEntityLoader}
                placeholder={t("Search for a product...")}
              />
            )}
          </FormSection>
          <button className="report-action primary">
            {t("Create Stock Count")}
          </button>
        </form>
      ) : (
        <>
          <section className="bg-white border rounded-2xl overflow-hidden">
            <div className="p-4 flex flex-wrap justify-between gap-3">
              <div>
                <h2 className="font-bold">
                  {count.document_number ||
                    t("Legacy stock count")}
                </h2>
                <StatusBadge value={count.status} />
              </div>
              <PrintDocumentButton type="stock_count" id={Number(count.id)} />
            </div>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr>
                    <th>{t("Product")}</th>
                    <th>
                      {t("Batch / Lot")}{" "}
                      <FieldHelp text="The manufacturer's batch number used for expiry and recall tracking." />
                    </th>
                    <th>{t("System Quantity")}</th>
                    <th>{t("Physical Quantity")}</th>
                    <th>{t("Difference")}</th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((x) => {
                    const key = Number(x.batch_id),
                      actual = Number(counts[key] ?? x.system_quantity);
                    return (
                      <tr key={key}>
                        <td>
                          <bdi className="block font-medium">
                            {String(x.product_name)}
                          </bdi>
                          <bdi dir="ltr" className="block text-xs text-gray-500">
                            {String(x.product_barcode)}
                          </bdi>
                        </td>
                        <td>
                          <bdi dir="ltr" className="block font-medium">
                            {String(x.batch_number)}
                          </bdi>
                          <span className="block text-xs text-gray-500">
                            {t("Expiry")}: {formatDateOnly(x.expiry_date ? String(x.expiry_date) : null)}
                          </span>
                        </td>
                        <td>{String(x.system_quantity)}</td>
                        <td>
                          <label>
                            <span className="sr-only">
                              {t("physicalQuantityFor", {
                                batch: String(x.batch_number),
                              })}
                            </span>
                            <input
                              className="cell-input"
                              type="number"
                              value={counts[key] ?? String(x.system_quantity)}
                              onChange={(e) =>
                                setCounts((v) => ({
                                  ...v,
                                  [key]: e.target.value,
                                }))
                              }
                            />
                          </label>
                        </td>
                        <td>{actual - Number(x.system_quantity)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {count.status === "draft" && (
              <div className="border-t p-4">
                <button
                  className="report-action primary"
                  onClick={() => void post()}
                >
                  {t("Post Stock Count")}
                </button>
                <p className="mt-2 text-xs text-gray-500">
                  {t(
                    "Posting creates permanent inventory movements for the differences above.",
                  )}
                </p>
              </div>
            )}
          </section>
          <AttachmentsSection type="stock_count" id={Number(count.id)} />
        </>
      )}
    </div>
  );
}
