import type { ComponentType, SVGProps } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import {
  SunIcon,
  CloudIcon,
  CloudSunIcon,
  CloudRainIcon,
  CloudDrizzleIcon,
  CloudSnowIcon,
  CloudLightningIcon,
  CloudFogIcon,
  WindIcon,
} from "./icons";

/**
 * Weather card — the first generative-UI tool widget.
 *
 * A FlowDrop "Weather" workflow shapes an Open-Meteo response into a payload
 * (this module's `WeatherPayload`, modelled on tool-ui.com's weather widget for
 * portability) and emits it as a `{ "__widget__": "weather_card", "payload": … }`
 * envelope. The dispatcher unwraps that into a `weather_card` tool-call frame
 * whose `arguments` ARE the payload; the SSE adapter turns the frame into a
 * tool-call part with `args = payload`, and this widget renders it inline —
 * current conditions plus a short forecast strip — instead of the Fallback card.
 *
 * Defensive by design: the payload is model/workflow-shaped, so anything may be
 * missing. We render only what's present and bail to `null` when there's no
 * usable current reading (the summary text the dispatcher also emits still
 * stands in).
 */

type ConditionCode =
  | "clear"
  | "partly-cloudy"
  | "cloudy"
  | "overcast"
  | "fog"
  | "drizzle"
  | "rain"
  | "heavy-rain"
  | "thunderstorm"
  | "snow"
  | "sleet"
  | "hail"
  | "windy";

type CurrentWeather = {
  temperature: number;
  tempMin?: number;
  tempMax?: number;
  conditionCode?: ConditionCode;
  windSpeed?: number;
};

type ForecastDay = {
  label: string;
  tempMin: number;
  tempMax: number;
  conditionCode?: ConditionCode;
};

export type WeatherPayload = {
  id?: string;
  location?: { name?: string };
  units?: { temperature?: "celsius" | "fahrenheit" };
  current?: CurrentWeather;
  forecast?: ForecastDay[];
  updatedAt?: string;
};

type Glyph = ComponentType<SVGProps<SVGSVGElement>>;

/** Condition code → glyph. Falls back to a plain cloud for anything unmapped. */
const GLYPHS: Record<ConditionCode, Glyph> = {
  clear: SunIcon,
  "partly-cloudy": CloudSunIcon,
  cloudy: CloudIcon,
  overcast: CloudIcon,
  fog: CloudFogIcon,
  drizzle: CloudDrizzleIcon,
  rain: CloudRainIcon,
  "heavy-rain": CloudRainIcon,
  thunderstorm: CloudLightningIcon,
  snow: CloudSnowIcon,
  sleet: CloudSnowIcon,
  hail: CloudSnowIcon,
  windy: WindIcon,
};

function glyphFor(code?: ConditionCode): Glyph {
  return (code && GLYPHS[code]) || CloudIcon;
}

/** "partly-cloudy" → "Partly cloudy". */
function conditionLabel(code?: ConditionCode): string {
  if (!code) return "";
  const words = code.replace(/-/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

const round = (n: number) => Math.round(n);

function WeatherCard(payload: WeatherPayload) {
  const current = payload.current;
  if (!current || typeof current.temperature !== "number") return null;

  const name = payload.location?.name?.trim() || "Unknown location";
  const unit = payload.units?.temperature === "fahrenheit" ? "°F" : "°C";
  const Icon = glyphFor(current.conditionCode);
  const forecast = (payload.forecast ?? []).slice(0, 7);

  // Today's hi/lo from the current reading, else the first forecast day.
  const hi = current.tempMax ?? forecast[0]?.tempMax;
  const lo = current.tempMin ?? forecast[0]?.tempMin;

  return (
    <div className="ain-weather">
      <div className="ain-weather__head">
        <span className="ain-weather__title">Weather</span>
        <span className="ain-weather__loc">{name}</span>
      </div>

      <div className="ain-weather__now">
        <Icon className="ain-weather__icon" />
        <div className="ain-weather__nowtext">
          <span className="ain-weather__temp">
            {round(current.temperature)}
            {unit}
          </span>
          {current.conditionCode && (
            <span className="ain-weather__cond">{conditionLabel(current.conditionCode)}</span>
          )}
        </div>
        <div className="ain-weather__meta">
          {typeof hi === "number" && typeof lo === "number" && (
            <span className="ain-weather__hilo">
              H {round(hi)}° · L {round(lo)}°
            </span>
          )}
          {typeof current.windSpeed === "number" && (
            <span className="ain-weather__wind">
              <WindIcon /> {round(current.windSpeed)} km/h
            </span>
          )}
        </div>
      </div>

      {forecast.length > 0 && (
        <div className="ain-weather__forecast">
          {forecast.map((day, i) => {
            const DayIcon = glyphFor(day.conditionCode);
            return (
              <div key={i} className="ain-weather__day">
                <span className="ain-weather__daylabel">{day.label}</span>
                <DayIcon className="ain-weather__dayicon" />
                <span className="ain-weather__dayrange">
                  {round(day.tempMax)}°
                  <span className="ain-weather__daymin">{round(day.tempMin)}°</span>
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

/**
 * Registers the weather card for the `weather_card` tool. Mount once inside the
 * AssistantRuntimeProvider; it renders nothing itself. `args` is the payload the
 * dispatcher passed through as the tool call's `arguments`.
 */
export const WeatherCardToolUI = makeSafeAssistantToolUI<WeatherPayload, unknown>({
  toolName: "weather_card",
  render: ({ args }) => <WeatherCard {...args} />,
});
