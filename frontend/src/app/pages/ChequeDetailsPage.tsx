import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useParams } from "react-router";
import { chequesApi } from "../../api/finance";
import {
  AttachmentsSection,
  ErrorNotice,
  MetricGrid,
  PrintDocumentButton,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";
export function ChequeDetailsPage() {
  const { t } = useTranslation(),
    id = Number(useParams().id),
    [data, setData] = useState<any>(null),
    [error, setError] = useState<any>(null),
    [loading, setLoading] = useState(true);
  useEffect(() => {
    setLoading(true);
    chequesApi
      .detail(id)
      .then((r) => setData(r.data))
      .catch((v) =>
        setError(normalizeError(v, "Unable to load cheque details.")),
      )
      .finally(() => setLoading(false));
  }, [id]);
  const number = data?.check_number || data?.cheque_number || t("Legacy cheque");
  return (
    <div className="p-4 md:p-8 space-y-5">
      <h1 className="text-3xl font-bold">
        {t("Cheque Details")} <bdi dir="ltr">{number}</bdi>
      </h1>
      <ErrorNotice error={error} />
      {loading && (
        <div className="p-10 text-center text-gray-500">
          {t("Loading cheque details…")}
        </div>
      )}
      {data && (
        <>
          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div className="p-4 bg-white border rounded-xl">
              <p className="text-xs uppercase text-gray-500">{t("Status")}</p>
              <StatusBadge value={data.status} />
            </div>
            <div className="p-4 bg-white border rounded-xl">
              <p className="text-xs uppercase text-gray-500">
                {t("Direction")}
              </p>
              <strong className="text-xl">
                {t(data.direction === "incoming" ? "Incoming" : "Outgoing")}
              </strong>
            </div>
          </div>
          <MetricGrid
            data={data}
            keys={[
              "bank_name",
              "amount",
              "due_date",
              "external_reference",
              "created_at",
              "cleared_at",
              "bounced_at",
            ]}
          />
          <PrintDocumentButton type="cheque" id={id} />
          <AttachmentsSection type="cheque" id={id} />
        </>
      )}
    </div>
  );
}
