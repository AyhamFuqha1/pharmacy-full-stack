import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { ArrowLeft } from "lucide-react";
import { useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { customersApi } from "../../api/customers";
import { receiptVouchersApi } from "../../api/finance";
import type { ReceiptVoucherDto } from "../../types/finance";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import { PrintDocumentButton, StatusBadge } from "../components/ErpPrimitives";
export function ReceiptPage() {
  const { t } = useTranslation(),
    { id } = useParams(),
    navigate = useNavigate(),
    voucherId = Number(id),
    [voucher, setVoucher] = useState<ReceiptVoucherDto | null>(null),
    [customer, setCustomer] = useState(""),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null);
  useEffect(() => {
    if (!Number.isInteger(voucherId) || voucherId <= 0) {
      setError(new ApiClientError(404, t("Receipt not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    receiptVouchersApi
      .detail(voucherId, controller.signal)
      .then(async (response) => {
        setVoucher(response.data);
        try {
          setCustomer(
            (
              await customersApi.detail(
                response.data.customer_id,
                controller.signal,
              )
            ).data.name,
          );
        } catch {
          setCustomer(
            t("customerReference", { id: response.data.customer_id }),
          );
        }
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load receipt.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [voucherId, t]);
  if (loading)
    return <div className="p-8 text-gray-500">{t("Loading receipt…")}</div>;
  if (error || !voucher)
    return (
      <div className="p-8 text-red-700">
        {error?.message ?? t("Receipt not found.")}
      </div>
    );
  return (
    <div className="p-8">
      <div className="print:hidden flex justify-between mb-6">
        <button onClick={() => navigate("/payments")} className="flex gap-2">
          <ArrowLeft /> {t("Back to Customer Payments")}
        </button>
        <PrintDocumentButton type="receipt" id={voucherId} />
      </div>
      <div className="max-w-3xl mx-auto bg-white rounded-2xl border p-10">
        <div className="flex justify-between border-b pb-6">
          <div>
            <h1 className="text-3xl font-bold">
              {t("Customer Payment Receipt")}
            </h1>
            <p className="text-gray-500">{t("Persisted payment document")}</p>
          </div>
          <div className="text-end">
            <strong>
              <bdi dir="ltr">{voucher.document_number || "—"}</bdi>
            </strong>
            <p>
              <StatusBadge value={voucher.status} />
            </p>
          </div>
        </div>
        <div className="flex justify-between py-8">
          <div>
            <p className="text-sm text-gray-500">{t("Received from")}</p>
            <p className="text-xl font-bold">{customer}</p>
          </div>
          <div className="text-end">
            <p className="text-sm text-gray-500">{t("Payment Date")}</p>
            <p className="font-bold">
              <bdi dir="ltr">{formatDateOnly(voucher.date)}</bdi>
            </p>
          </div>
        </div>
        <div className="bg-emerald-50 rounded-2xl p-8 text-center">
          <p className="text-emerald-700">{t("Payment Amount")}</p>
          <p className="text-4xl font-bold text-emerald-700">
            {formatMoney(voucher.amount)}
          </p>
        </div>
      </div>
    </div>
  );
}
