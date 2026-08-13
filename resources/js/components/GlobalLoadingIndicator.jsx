import React, { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { getPendingApiActivity } from '../loadingActivity';

const API_DELAY_MS = 140;
const ROUTE_PULSE_MS = 360;
const MIN_VISIBLE_MS = 260;

/**
 * A deliberately small, non-blocking indicator for section changes and API
 * work. Slow data requests keep it visible; quick background requests do not
 * cause an irritating flash.
 */
export default function GlobalLoadingIndicator() {
    const location = useLocation();
    const [pendingRequests, setPendingRequests] = useState(getPendingApiActivity);
    const [routeLoading, setRouteLoading] = useState(false);
    const [visible, setVisible] = useState(false);
    const previousRoute = useRef(null);
    const routeTimer = useRef(null);
    const showTimer = useRef(null);
    const hideTimer = useRef(null);
    const visibleSince = useRef(0);

    useEffect(() => {
        const updatePendingRequests = (event) => {
            setPendingRequests(Number(event.detail?.pending ?? getPendingApiActivity()));
        };

        window.addEventListener('hg:api-activity', updatePendingRequests);
        setPendingRequests(getPendingApiActivity());

        return () => window.removeEventListener('hg:api-activity', updatePendingRequests);
    }, []);

    useEffect(() => {
        const currentRoute = `${location.pathname}${location.search}${location.hash}`;
        if (previousRoute.current !== null && previousRoute.current !== currentRoute) {
            window.clearTimeout(routeTimer.current);
            setRouteLoading(true);
            routeTimer.current = window.setTimeout(() => setRouteLoading(false), ROUTE_PULSE_MS);
        }
        previousRoute.current = currentRoute;
    }, [location.hash, location.pathname, location.search]);

    useEffect(() => () => {
        window.clearTimeout(routeTimer.current);
        window.clearTimeout(showTimer.current);
        window.clearTimeout(hideTimer.current);
    }, []);

    const busy = routeLoading || pendingRequests > 0;

    useEffect(() => {
        window.clearTimeout(showTimer.current);
        window.clearTimeout(hideTimer.current);

        if (busy) {
            if (!visible) {
                showTimer.current = window.setTimeout(() => {
                    visibleSince.current = Date.now();
                    setVisible(true);
                }, routeLoading ? 0 : API_DELAY_MS);
            }
            return undefined;
        }

        if (visible) {
            const elapsed = Date.now() - visibleSince.current;
            hideTimer.current = window.setTimeout(
                () => setVisible(false),
                Math.max(0, MIN_VISIBLE_MS - elapsed),
            );
        }

        return undefined;
    }, [busy, routeLoading, visible]);

    if (!visible) {
        return null;
    }

    return (
        <div className="hg-global-loading" role="status" aria-live="polite" aria-label="Loading">
            <span className="hg-global-loading__spinner" aria-hidden="true" />
            <span>Loading</span>
        </div>
    );
}
