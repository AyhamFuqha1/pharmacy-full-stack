import { apiClient } from './client';
import type { ApiSuccess } from '../types/api';
import type { JsonRecord } from './erpOperations';
export const attachmentsApi = {
  list:(type:string,id:number,signal?:AbortSignal)=>apiClient.get<ApiSuccess<JsonRecord[]>>(`attachments/${type}/${id}`,signal),
  upload:(type:string,id:number,file:File)=>{const data=new FormData();data.append('file',file);return apiClient.post<ApiSuccess<JsonRecord>>(`attachments/${type}/${id}`,data)},
  remove:(id:number)=>apiClient.delete<ApiSuccess<null>>(`attachment-files/${id}`),
  download:(id:number)=>apiClient.downloadGet(`attachment-files/${id}`),
};
