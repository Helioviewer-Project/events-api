#!/usr/bin/env python3
"""
HPC (Earth) to Heliographic Stonyhurst Coordinate Transformer

================================================================================
COORDINATE SYSTEMS OVERVIEW
================================================================================

Helioprojective Cartesian (HPC):
- A 2D coordinate system as seen from an observer (in our case, Earth)
- Tx (theta_x): Angular distance from Sun center in the East-West direction (arcseconds)
- Ty (theta_y): Angular distance from Sun center in the North-South direction (arcseconds)
- Origin is at the center of the solar disk as seen from the observer
- Used by solar telescopes and instruments like RHESSI

Heliographic Stonyhurst (HGS):
- A 3D coordinate system fixed to the Sun's surface
- Longitude: Angular distance from the solar central meridian (-180 to +180 degrees)
  - Positive = West, Negative = East (as seen from Earth)
- Latitude: Angular distance from the solar equator (-90 to +90 degrees)
  - Positive = North, Negative = South
- The central meridian (0 longitude) is the meridian that passes through the
  apparent center of the solar disk as seen from Earth

================================================================================
TRANSFORMATION DETAILS
================================================================================

This script converts HPC coordinates (what a telescope sees) to HGS coordinates
(where on the Sun's surface the feature actually is).

Key assumptions:
1. Observer is Earth - The HPC coordinates are from Earth's viewpoint
2. Point is on solar surface - We assume the feature is at 1 solar radius (R_sun)
   from the Sun's center. This is valid for surface features like flares.

The transformation accounts for:
- Earth's position relative to the Sun at the observation time
- Solar rotation and the Sun's orientation (B0 angle, P angle, L0)
- Projection effects (limb foreshortening)

================================================================================
USAGE
================================================================================

Input (stdin):  hpc_x hpc_y obstime
                - hpc_x: Helioprojective X in arcseconds
                - hpc_y: Helioprojective Y in arcseconds
                - obstime: ISO 8601 timestamp (e.g., 2002-02-12T02:19:22Z)

Output (stdout): hgs_lon hgs_lat
                 - hgs_lon: Stonyhurst longitude in degrees (-180 to +180)
                 - hgs_lat: Stonyhurst latitude in degrees (-90 to +90)

Example:
    echo "958 -118 2002-02-12T02:19:22Z" | python hpc_earth_to_stonyhurst.py
    # Output: 75.123456 -7.654321

================================================================================
PERFORMANCE
================================================================================

This script is optimized for batch processing:
- Reads ALL input lines first into memory
- Processes all coordinates as numpy arrays in a single SkyCoord operation
- This is ~100x faster than processing line-by-line due to:
  - Single Time parsing operation for all timestamps
  - Vectorized coordinate transformations in astropy/sunpy
  - Reduced Python loop overhead

================================================================================
"""

import sys
import numpy as np
from sunpy.coordinates import frames
from astropy.coordinates import SkyCoord
from astropy.time import Time
import astropy.units as u


def main():
    """
    Main function: Read all input, process as batch, output results.

    Returns:
        0 on success, 1 on error
    """

    # =========================================================================
    # PHASE 1: Collect all input from stdin
    # =========================================================================
    # We read everything first to enable batch processing, which is much faster
    # than processing line-by-line due to numpy/astropy vectorization.

    hpc_x_list = []    # Helioprojective X coordinates (arcseconds)
    hpc_y_list = []    # Helioprojective Y coordinates (arcseconds)
    obstime_list = []  # Observation timestamps (ISO 8601 strings)

    for line_num, line in enumerate(sys.stdin, 1):
        # Strip whitespace from line
        line = line.strip()

        # Skip empty lines and comments (lines starting with #)
        if not line or line.startswith('#'):
            continue

        # Split line into parts: "hpc_x hpc_y obstime"
        # Using split(None, 2) splits on any whitespace, max 3 parts
        # This allows obstime to contain spaces if needed
        parts = line.split(None, 2)

        # Validate we have all 3 required values
        if len(parts) < 3:
            print(f"ERROR: Line {line_num}: Need 3 values (hpc_x hpc_y obstime), got {len(parts)}", file=sys.stderr)
            return 1

        # Parse the numeric coordinates
        try:
            hpc_x_list.append(float(parts[0]))  # Tx in arcseconds
            hpc_y_list.append(float(parts[1]))  # Ty in arcseconds
        except ValueError as e:
            print(f"ERROR: Line {line_num}: Invalid number format: {e}", file=sys.stderr)
            return 1

        # Store the timestamp string (will be parsed by astropy.Time later)
        obstime_list.append(parts[2])

    # If no valid input lines, exit successfully (nothing to do)
    if not hpc_x_list:
        return 0

    # =========================================================================
    # PHASE 2: Convert to numpy arrays for vectorized processing
    # =========================================================================
    # Numpy arrays enable fast vectorized operations in astropy/sunpy

    hpc_x = np.array(hpc_x_list)  # Shape: (N,) where N = number of coordinates
    hpc_y = np.array(hpc_y_list)  # Shape: (N,)

    # =========================================================================
    # PHASE 3: Parse all timestamps at once
    # =========================================================================
    # astropy.Time can parse a list of ISO strings efficiently in one call
    # This is much faster than parsing timestamps one at a time

    obstimes = Time(obstime_list)  # Returns Time object with shape (N,)

    # =========================================================================
    # PHASE 4: Create HPC coordinate array
    # =========================================================================
    # SkyCoord accepts array inputs for batch processing
    #
    # Parameters:
    # - Tx, Ty: The helioprojective coordinates with units (arcseconds)
    # - obstime: When each observation was made (needed for Earth's position)
    # - observer: Where the observation was made from ('earth')
    # - frame: The coordinate frame (Helioprojective)
    #
    # Note: Each coordinate can have a different obstime, which is why we
    # pass the full obstimes array rather than a single time.

    hpc_coords = SkyCoord(
        Tx=hpc_x * u.arcsec,      # East-West angle from Sun center
        Ty=hpc_y * u.arcsec,      # North-South angle from Sun center
        obstime=obstimes,          # Observation time for each coordinate
        observer='earth',          # Observer location (Earth)
        frame=frames.Helioprojective  # The HPC coordinate frame
    )

    # =========================================================================
    # PHASE 5: Transform to Heliographic Stonyhurst
    # =========================================================================
    # This is where the magic happens! SunPy handles:
    # - Looking up Earth's position at each obstime
    # - Computing the Sun's orientation (B0, P, L0 angles)
    # - Projecting from 2D (what we see) to 3D (where it is on the Sun)
    # - Assuming the point is on the solar surface (1 R_sun)
    #
    # The transform_to() method is vectorized, so it processes all
    # coordinates in one efficient operation.

    hgs_coords = hpc_coords.transform_to(frames.HeliographicStonyhurst)

    # =========================================================================
    # PHASE 6: Extract longitude and latitude arrays
    # =========================================================================
    #
    # wrap_at(180*u.deg) ensures longitude is in range [-180, +180] degrees
    # (without this, it would be [0, 360] degrees)
    #
    # to_value(u.deg) extracts the numeric value in degrees as a numpy array

    lons = hgs_coords.lon.wrap_at(180*u.deg).to_value(u.deg)  # Shape: (N,)
    lats = hgs_coords.lat.to_value(u.deg)                      # Shape: (N,)

    # =========================================================================
    # PHASE 7: Output results
    # =========================================================================
    # Print one line per coordinate pair, matching input order
    # Format: "longitude latitude" with 6 decimal places

    for lon, lat in zip(lons, lats):
        print(f"{lon:.6f} {lat:.6f}")

    return 0


if __name__ == "__main__":
    # Run main() and exit with its return code
    sys.exit(main())
