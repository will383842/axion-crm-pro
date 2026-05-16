import { stubWorker } from './_stub';
export async function startGoogleSearchWorker(): Promise<void> { await stubWorker('worker-google-search'); }
