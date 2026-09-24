import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { PrintDocumentButton } from "./ErpPrimitives";
export function CreatedPaymentPrint() {
  const { t } = useTranslation(),
    [id, setId] = useState(0);
  useEffect(() => {
    const handler = (event: Event) =>
      setId(Number((event as CustomEvent<number>).detail));
    window.addEventListener("payment-voucher-created", handler);
    return () => window.removeEventListener("payment-voucher-created", handler);
  }, []);
  return id ? (
    <div className="px-4 md:px-8 pb-8 flex justify-end">
      <PrintDocumentButton
        type="payment"
        id={id}
        label={t("printPaymentReference", { id })}
      />
    </div>
  ) : null;
}
