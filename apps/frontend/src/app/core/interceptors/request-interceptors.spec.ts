import { HttpHeaders, HttpRequest, HttpResponse } from "@angular/common/http";
import { firstValueFrom, of } from "rxjs";
import { environment } from "../../../environments/environment";
import { CORRELATION_ID_HEADER, REQUEST_ID_HEADER } from "../observability/correlation";
import { apiBaseUrlInterceptor } from "./api-base-url.interceptor";
import { correlationInterceptor } from "./correlation.interceptor";

describe("apiBaseUrlInterceptor", () => {
  const originalBaseUrl = environment.apiBaseUrl;

  afterEach(() => {
    environment.apiBaseUrl = originalBaseUrl;
  });

  it.each(["/api/v1/state", "/health", "/ready"])("premette la base configurata a %s", async (url) => {
    environment.apiBaseUrl = "https://backend.example/";
    const next = jest.fn((request: HttpRequest<unknown>) => of(new HttpResponse({ url: request.url })));

    await firstValueFrom(apiBaseUrlInterceptor(new HttpRequest("GET", url), next));

    expect(next.mock.calls[0][0].url).toBe(`https://backend.example${url}`);
  });

  it("lascia invariata la richiesta quando la base non e' configurata", async () => {
    environment.apiBaseUrl = "";
    const request = new HttpRequest("GET", "/api/v1/state");
    const next = jest.fn((value: HttpRequest<unknown>) => of(new HttpResponse({ url: value.url })));

    await firstValueFrom(apiBaseUrlInterceptor(request, next));

    expect(next).toHaveBeenCalledWith(request);
  });

  it("non modifica URL assoluti o asset della SPA", async () => {
    environment.apiBaseUrl = "https://backend.example";
    const request = new HttpRequest("GET", "/assets/logo.svg");
    const next = jest.fn((value: HttpRequest<unknown>) => of(new HttpResponse({ url: value.url })));

    await firstValueFrom(apiBaseUrlInterceptor(request, next));

    expect(next).toHaveBeenCalledWith(request);
  });
});

describe("correlationInterceptor", () => {
  it("aggiunge request id e correlation id con lo stesso valore", async () => {
    const next = jest.fn((request: HttpRequest<unknown>) => of(new HttpResponse({ headers: request.headers })));

    await firstValueFrom(correlationInterceptor(new HttpRequest("GET", "/api/v1/state"), next));

    const forwarded = next.mock.calls[0][0];
    expect(forwarded.headers.get(REQUEST_ID_HEADER)).toBeTruthy();
    expect(forwarded.headers.get(CORRELATION_ID_HEADER)).toBe(forwarded.headers.get(REQUEST_ID_HEADER));
  });

  it("preserva una richiesta gia' correlata", async () => {
    const request = new HttpRequest("GET", "/api/v1/state", {
      headers: new HttpHeaders({ [REQUEST_ID_HEADER]: "request-esistente" })
    });
    const next = jest.fn((value: HttpRequest<unknown>) => of(new HttpResponse({ headers: value.headers })));

    await firstValueFrom(correlationInterceptor(request, next));

    expect(next).toHaveBeenCalledWith(request);
    expect(next.mock.calls[0][0].headers.has(CORRELATION_ID_HEADER)).toBe(false);
  });
});
