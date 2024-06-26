import {combineReducers} from '../combineReducers';

import * as Preferences from './Preferences';
import * as Name from './Name';
import * as Impersonate from './Impersonate';

const all = {Preferences, Name, Impersonate};

//
// Export the subreducer state shape interface
//
export interface State {
    preferences: Preferences.State;
    name: Name.State;
    impersonate: Impersonate.State;
}

function typedKeys<T extends {}>(o: T) : Array<keyof T> {
    return Object.keys(o) as Array<keyof T>;
}

//
// Export the actionTypes
//
export const actionTypes = typedKeys(all).reduce((acc, cur) => ({...acc, [cur]: all[cur].actionTypes}), {});

//
// Export the actions
//
export const actions = typedKeys(all).reduce((acc, cur) => ({...acc, [cur]: all[cur].actions}), {});

//
// Export the reducer
//
export const reducer = combineReducers({
    preferences: Preferences.reducer,
    name: Name.reducer,
    impersonate: Impersonate.reducer
} as any);

//
// Export the selectors
//
export const selectors = typedKeys(all).reduce((acc, cur) => ({...acc, [cur]: all[cur].selectors}), {});
