import { PartyDetails } from "../components/PartyDetails";
import { Party360Workspace } from "../components/Party360Workspace";
import { useParams } from "react-router";

export function SupplierDetailsPage() {
  const id = Number(useParams().id);
  return (
    <>
      <PartyDetails kind="supplier" />
      <div className="px-8 pb-8">
        <Party360Workspace kind="supplier" id={id} />
      </div>
    </>
  );
}
