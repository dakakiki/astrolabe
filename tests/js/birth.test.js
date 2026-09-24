import { describe, expect, it } from 'vitest';

import { birthFromApi, birthToPayload, emptyBirth, hasBirthInput } from '../../resources/js/lib/birth';

const fromList = {
    birth_date: '1985-07-15',
    birth_time: '14:30',
    time_accuracy: 'exact',
    birth_place: 'Novi Sad, Vojvodina',
    birth_country_code: 'RS',
    latitude: 45.25167,
    longitude: 19.83694,
    birth_timezone: 'Europe/Belgrade',
    place_id: 3194360,
    geocode_source: 'geonames',
    data_source: null,
    notes: null,
};

describe('birth form state', () => {
    it('sends only the place id for a place from the list, so the server copies it in', () => {
        expect(birthToPayload(birthFromApi(fromList))).toEqual({
            time_accuracy: 'exact',
            birth_date: '1985-07-15',
            birth_time: '14:30',
            data_source: null,
            notes: null,
            place_id: 3194360,
        });
    });

    it('sends hand-entered coordinates and zone', () => {
        const state = { ...emptyBirth(), mode: 'manual', latitude: '43.1', longitude: '21.7', birth_timezone: 'Europe/Belgrade' };

        expect(birthToPayload(state)).toMatchObject({
            latitude: '43.1',
            longitude: '21.7',
            birth_timezone: 'Europe/Belgrade',
            birth_place: null,
        });
        expect(birthToPayload(state)).not.toHaveProperty('place_id');
    });

    it('drops the time when it is unknown', () => {
        const state = { ...birthFromApi(fromList), time_accuracy: 'unknown' };

        expect(birthToPayload(state).birth_time).toBeNull();
    });

    it('reads hand-entered data back as manual', () => {
        const state = birthFromApi({ ...fromList, geocode_source: 'manual', place_id: null });

        expect(state.mode).toBe('manual');
        expect(state.place).toBeNull();
        expect(state.latitude).toBe(45.25167);
    });

    it('knows when nothing has been entered', () => {
        expect(hasBirthInput(emptyBirth())).toBe(false);
        expect(hasBirthInput({ ...emptyBirth(), birth_date: '1990-01-01' })).toBe(true);
    });
});
