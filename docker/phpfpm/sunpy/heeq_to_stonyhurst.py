#!/usr/bin/env python3
"""
HEEQ to Heliographic Stonyhurst Coordinate Transformer
Reads from stdin: heeq_lon heeq_lat time
Writes to stdout: hgs_lon hgs_lat
Time format: ISO 8601 (YYYY-MM-DDTHH:MM:SS or with Z suffix)
All times are assumed to be UTC
"""

import sys
import numpy as np
from sunpy.coordinates import frames
from astropy.coordinates import SkyCoord
from astropy.time import Time
import astropy.units as u


def heeq_to_stonyhurst(lon_heeq_deg, lat_heeq_deg, time_iso):
    """
    DONKI HEEQ (lon, lat) @ Time -> Stonyhurst (lon, lat), degrees.
    
    Args:
        lon_heeq_deg: HEEQ longitude in degrees
        lat_heeq_deg: HEEQ latitude in degrees
        time_iso: ISO format time string
    
    Returns:
        tuple: (hgs_lon_deg, hgs_lat_deg)
    """
    t = Time(time_iso)

    # Build unit vector from HEEQ spherical angles (radius arbitrary for angles)
    lon = np.deg2rad(lon_heeq_deg)
    lat = np.deg2rad(lat_heeq_deg)
    x = np.cos(lat) * np.cos(lon)
    y = np.cos(lat) * np.sin(lon)
    z = np.sin(lat)

    # Treat this vector in the HGS Cartesian basis; then read Stonyhurst angles at obstime
    v = SkyCoord(x=x*u.one, y=y*u.one, z=z*u.one,
                 frame=frames.HeliographicStonyhurst,
                 representation_type='cartesian',
                 obstime=t)

    stony = v.transform_to(frames.HeliographicStonyhurst(obstime=t))
    
    return (
        float(stony.lon.wrap_at(180*u.deg).to_value(u.deg)),
        float(stony.lat.to_value(u.deg))
    )


def main():
    """Process stdin line by line"""
    for line_num, line in enumerate(sys.stdin, 1):
        line = line.strip()
        
        # Skip empty lines and comments
        if not line or line.startswith('#'):
            continue
        
        try:
            # Parse input: expecting "heeq_lon heeq_lat time"
            parts = line.split(None, 2)  # Split on whitespace, max 3 parts
            
            if len(parts) < 3:
                print(f"ERROR: Line {line_num}: Need 3 values (lon lat time), got {len(parts)}", file=sys.stderr)
                continue
            
            # Parse coordinates in degrees
            heeq_lon = float(parts[0])
            heeq_lat = float(parts[1])
            time_iso = parts[2]
            
            # Perform transformation
            hgs_lon, hgs_lat = heeq_to_stonyhurst(heeq_lon, heeq_lat, time_iso)
            
            # Output: longitude latitude (in degrees)
            print(f"{hgs_lon:.6f} {hgs_lat:.6f}")
            sys.stdout.flush()  # Ensure immediate output for pipe usage
            
        except ValueError as e:
            print(f"ERROR: Line {line_num}: Invalid number format: {e}", file=sys.stderr)
        except Exception as e:
            print(f"ERROR: Line {line_num}: {e}", file=sys.stderr)
    
    return 0


if __name__ == "__main__":
    sys.exit(main())