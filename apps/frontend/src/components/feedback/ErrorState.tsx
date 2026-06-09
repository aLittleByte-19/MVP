import { Alert } from "./Alert";

type ErrorStateProps = {
  message: string;
  title?: string;
};

export function ErrorState({ message, title = "Servizio non disponibile" }: ErrorStateProps) {
  return <Alert title={title}>{message}</Alert>;
}
